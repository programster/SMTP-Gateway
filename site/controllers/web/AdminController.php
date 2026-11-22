<?php

/*
 * Administering the service: the accounts that can log in to the web interface (adding them, blocking and unblocking
 * them, archiving them, restoring archived ones and deleting archived ones), and the application's logs. Archiving and
 * deleting need the password of whoever does it, and nobody can block, archive or delete their own account.
 */

declare(strict_types = 1);

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Interfaces\RouteCollectorProxyInterface;


class AdminController extends AbstractController
{
    public static function registerRoutes(RouteCollectorProxyInterface $group) : void
    {
        $group->get('/admin/users', [self::class, 'listUsers'])->setName('admin.users');
        $group->post('/admin/users', [self::class, 'createUser'])->setName('admin.users.create');
        $group->get('/admin/users/{id}', [self::class, 'showUser'])->setName('admin.users.user');
        $group->post('/admin/users/{id}/email', [self::class, 'changeEmail'])->setName('admin.users.email');
        $group->post('/admin/users/{id}/block', [self::class, 'blockUser'])->setName('admin.users.block');
        $group->post('/admin/users/{id}/unblock', [self::class, 'unblockUser'])->setName('admin.users.unblock');
        $group->post('/admin/users/{id}/archive', [self::class, 'archiveUser'])->setName('admin.users.archive');
        $group->get('/admin/users/{id}/restore', [self::class, 'showRestoreUser'])->setName('admin.users.restore');

        $group->post('/admin/users/{id}/restore', [self::class, 'restoreUser'])
            ->setName('admin.users.restore.submit');

        $group->post('/admin/users/{id}/delete', [self::class, 'deleteUser'])->setName('admin.users.delete');
        $group->post('/admin/users/{id}/mfa-grace', [self::class, 'grantMfaGrace'])->setName('admin.users.mfa-grace');

        $group->post('/admin/users/{id}/mfa-grace/cancel', [self::class, 'cancelMfaGrace'])
            ->setName('admin.users.mfa-grace.cancel');

        $group->post('/admin/users/{id}/password-reset', [self::class, 'sendPasswordReset'])
            ->setName('admin.users.password-reset');

        $group->post('/admin/users/{id}/temporary-password', [self::class, 'setTemporaryPassword'])
            ->setName('admin.users.temporary-password');

        $group->get('/admin/logs', [self::class, 'showLogs'])->setName('admin.logs');
        $group->get('/admin/logs/{id}', [self::class, 'showLog'])->setName('admin.logs.entry');
    }


    public function listUsers(Request $request, Response $response) : Response
    {
        $isShowingArchived = (self::getQueryParam($request, 'archived') === '1');
        return $this->renderUsers($response, '', NewAccountForm::createEmpty(), 200, $isShowingArchived);
    }


    /**
     * Create an account: either emailing its owner a link to choose their own password, or with a password given here
     * (which they can be made to change when they first log in).
     */
    public function createUser(Request $request, Response $response) : Response
    {
        $source = AccountPasswordSource::tryFrom(self::getFormField($request, 'password_source'));
        $mustChange = (self::getFormField($request, 'must_change_password') === '1');
        $email = trim(self::getFormField($request, 'email'));
        $form = new NewAccountForm($email, $source ?? AccountPasswordSource::EMAILED_LINK, $mustChange);

        try
        {
            $message = match ($source) {
                AccountPasswordSource::EMAILED_LINK => self::createAndInvite($request, $email),
                AccountPasswordSource::SET_NOW => self::createWithPassword($request, $email, $mustChange),
                null => throw new InvalidInputException('Choose how they get their password.'),
            };

            WebSession::flash($message);
            $result = self::redirect($response, '/admin/users');
        }
        catch (InvalidInputException $e)
        {
            $result = $this->renderUsers($response, $e->getMessage(), $form, 400, false);
        }

        return $result;
    }


    /**
     * Show an account: when it last logged in and from where, its second factors, and forms to change its email
     * address and delete it.
     *
     * @throws NotFoundException - if there's no such account.
     */
    public function showUser(Request $request, Response $response, array $args) : Response
    {
        $user = AdminUserRepository::load((string)$args['id']);
        $email = ($user->getStatus() === AccountStatus::ARCHIVED) ? '' : $user->getEmail();
        return self::renderUserPage($response, $user, $email, '', 200);
    }


    /**
     * @throws NotFoundException - if there's no such account.
     */
    public function changeEmail(Request $request, Response $response, array $args) : Response
    {
        $user = AdminUserRepository::load((string)$args['id']);
        $email = trim(self::getFormField($request, 'email'));

        try
        {
            $changed = AdminUserRepository::changeEmail($user, new EmailAddress($email));

            ServiceFactory::getLogger(LogCategory::ADMIN)->notice('Account\'s email address changed', [
                'user_id' => $changed->getId(),
                'by_user_id' => WebSession::getUser()->getId(),
            ]);

            WebSession::flash("The account's email address was changed to \"{$changed->getEmail()}\".");
            $result = self::redirect($response, '/admin/users/' . rawurlencode($changed->getId()));
        }
        catch (InvalidInputException $e)
        {
            $result = self::renderUserPage($response, $user, $email, $e->getMessage(), 400);
        }

        return $result;
    }


    /**
     * Block an account, so it can't log in (and is logged out) until it's unblocked.
     *
     * @throws NotFoundException - if there's no such account.
     */
    public function blockUser(Request $request, Response $response, array $args) : Response
    {
        $block = function (AdminUser $user) : string {
            AdminUserRepository::block($user);
            return 'The account was blocked: it can\'t log in until it\'s unblocked.';
        };

        return $this->changeAccount($request, $response, (string)$args['id'], 'Account blocked', $block);
    }


    /**
     * Unblock an account, so it can log in again.
     *
     * @throws NotFoundException - if there's no such account.
     */
    public function unblockUser(Request $request, Response $response, array $args) : Response
    {
        $unblock = function (AdminUser $user) : string {
            AdminUserRepository::unblock($user);
            return 'The account was unblocked: it can log in again.';
        };

        return $this->changeAccount($request, $response, (string)$args['id'], 'Account unblocked', $unblock);
    }


    /**
     * Archive an account, once the password of whoever is doing it has been checked: block it, and remove its email
     * address and sign-in details, keeping only its ID.
     *
     * @throws NotFoundException - if there's no such account.
     */
    public function archiveUser(Request $request, Response $response, array $args) : Response
    {
        $password = self::getFormField($request, 'current_password');

        $archive = function (AdminUser $user) use ($password) : string {
            AdminUserRepository::requirePassword(WebSession::getUser(), $password);
            AdminUserRepository::archive($user);
            return 'The account was archived. It can be restored or deleted from the Archived tab.';
        };

        return $this->changeAccount($request, $response, (string)$args['id'], 'Account archived', $archive);
    }


    /**
     * @throws NotFoundException - if there's no such account.
     */
    public function showRestoreUser(Request $request, Response $response, array $args) : Response
    {
        $user = AdminUserRepository::load((string)$args['id']);

        $result = ($user->getStatus() === AccountStatus::ARCHIVED)
            ? self::renderRestorePage($response, $user, NewAccountForm::createEmpty(), '', 200)
            : self::redirect($response, '/admin/users/' . rawurlencode($user->getId()));

        return $result;
    }


    /**
     * Restore an archived account, with its email address again, and either an emailed link for its owner to choose
     * its password, or a password set now. It keeps its ID.
     *
     * @throws NotFoundException - if there's no such account.
     */
    public function restoreUser(Request $request, Response $response, array $args) : Response
    {
        $user = AdminUserRepository::load((string)$args['id']);
        $source = AccountPasswordSource::tryFrom(self::getFormField($request, 'password_source'));
        $mustChange = (self::getFormField($request, 'must_change_password') === '1');
        $email = trim(self::getFormField($request, 'email'));
        $form = new NewAccountForm($email, $source ?? AccountPasswordSource::EMAILED_LINK, $mustChange);

        try
        {
            $message = match ($source) {
                AccountPasswordSource::EMAILED_LINK => self::restoreAndInvite($request, $user, $email),
                AccountPasswordSource::SET_NOW => self::restoreWithPassword($request, $user, $email, $mustChange),
                null => throw new InvalidInputException('Choose how they get their password.'),
            };

            $this->logAccountChange('Account restored', $user);
            WebSession::flash($message);
            $result = self::redirect($response, '/admin/users/' . rawurlencode($user->getId()));
        }
        catch (InvalidInputException | AccountStateException $e)
        {
            $result = self::renderRestorePage($response, $user, $form, $e->getMessage(), 400);
        }

        return $result;
    }


    /**
     * Delete an archived account for good, once the password of whoever is doing it has been checked.
     *
     * @throws NotFoundException - if there's no such account.
     */
    public function deleteUser(Request $request, Response $response, array $args) : Response
    {
        $password = self::getFormField($request, 'current_password');

        $delete = function (AdminUser $user) use ($password) : string {
            AdminUserRepository::requirePassword(WebSession::getUser(), $password);
            AdminUserRepository::delete($user);
            return 'The account was deleted for good.';
        };

        return $this->changeAccount($request, $response, (string)$args['id'], 'Account deleted', $delete);
    }


    /**
     * Give an account a one-hour grace period, once the password of whoever is doing it has been checked.
     * @throws NotFoundException - if there's no such account.
     */
    public function grantMfaGrace(Request $request, Response $response, array $args) : Response
    {
        $password = self::getFormField($request, 'current_password');

        $grant = function (AdminUser $user) use ($password) : string {
            AdminUserRepository::requirePassword(WebSession::getUser(), $password);
            MfaGraceService::grant($user);
            return 'The account has a grace period for the next hour.';
        };

        return $this->changeOnPage($response, (string)$args['id'], 'Account given an MFA grace period', $grant);
    }


    /**
     * @throws NotFoundException - if there's no such account.
     */
    public function cancelMfaGrace(Request $request, Response $response, array $args) : Response
    {
        $cancel = function (AdminUser $user) : string {
            MfaGraceService::cancel($user);
            return 'The grace period was cancelled.';
        };

        return $this->changeOnPage($response, (string)$args['id'], 'Account\'s MFA grace period cancelled', $cancel);
    }


    /**
     * Email an account a link to choose a new password.
     * @throws NotFoundException - if there's no such account.
     */
    public function sendPasswordReset(Request $request, Response $response, array $args) : Response
    {
        $clientIp = (string)ClientIpResolver::resolve($request);

        $send = function (AdminUser $user) use ($clientIp) : string {
            PasswordResetService::sendResetFromAdmin($user, $clientIp);
            return "A link to choose a new password was emailed to \"{$user->getEmail()}\". It works for an hour.";
        };

        return $this->changeOnPage($response, (string)$args['id'], 'Password reset link sent to account', $send);
    }


    /**
     * Give an account a random password, shown once, which it has to change when it next logs in.
     * @throws NotFoundException - if there's no such account.
     */
    public function setTemporaryPassword(Request $request, Response $response, array $args) : Response
    {
        $user = AdminUserRepository::load((string)$args['id']);

        try
        {
            if ($user->getId() === WebSession::getUser()->getId())
            {
                throw new InvalidInputException('You can\'t do this to your own account.');
            }

            $password = AdminUserRepository::setTemporaryPassword($user);
            $this->logAccountChange('Temporary password set for account', $user);
            $page = new ViewTemporaryPasswordPage($user, $password);
            $result = self::renderPage($response, 'Temporary password set', $page);
        }
        catch (InvalidInputException $e)
        {
            $email = ($user->getStatus() === AccountStatus::ARCHIVED) ? '' : $user->getEmail();
            $result = self::renderUserPage($response, $user, $email, $e->getMessage(), 400);
        }

        return $result;
    }


    public function showLogs(Request $request, Response $response) : Response
    {
        $from = self::getQueryParam($request, 'from');
        $to = self::getQueryParam($request, 'to');
        $search = (string)($request->getQueryParams()['search'] ?? '');
        $regexType = self::getQueryParam($request, 'regex');
        $syntax = MessageSearchSyntax::tryFrom($regexType) ?? MessageSearchSyntax::PLAIN_TEXT;
        $levels = self::readLogLevels($request->getQueryParams()['level'] ?? '');
        $category = LogCategory::tryFrom(self::getQueryParam($request, 'category'));
        $categories = ($category === null) ? new LogCategoryCollection() : new LogCategoryCollection($category);
        $contextName = self::getQueryParam($request, 'context_name');
        $contextValue = (string)($request->getQueryParams()['context_value'] ?? '');

        // Each part of the page that isn't valid (e.g. from a mistyped link) is left as its default.
        $sort = LogSort::tryFrom(self::getQueryParam($request, 'sort')) ?? LogSort::NEWEST_FIRST;
        $size = LogPageSize::tryFrom((int)self::getQueryParam($request, 'per_page')) ?? LogPageSize::FIFTY;
        $number = max(1, (int)self::getQueryParam($request, 'page'));
        $logPage = new LogPage($sort, $size, $number);
        $error = '';
        $result = LogSearchResult::createEmpty();

        try
        {
            $context = LogContextMatch::createFromFields($contextName, $contextValue);
            $filter = new LogFilter($from, $to, $search, $syntax, $levels, $categories, $context);
            $result = LogRepository::search($filter, $logPage);
        }
        catch (InvalidInputException $e)
        {
            $any = LogContextMatch::createForAny();
            $filter = new LogFilter('', '', $search, $syntax, $levels, $categories, $any);
            $error = $e->getMessage();
        }

        $page = new ViewLogsPage($result, $filter, $logPage, $from, $to, Settings::get()->getTimezone(), $error);
        return self::renderPage($response, 'Logs', $page, ($error === '') ? 200 : 400);
    }


    /**
     * Show one log entry in full, with its context.
     *
     * @throws NotFoundException - if there's no such entry.
     */
    public function showLog(Request $request, Response $response, array $args) : Response
    {
        $entry = LogRepository::load((string)$args['id']);
        $accounts = [];

        // What each account the entry names is now: its email address, "Archived account", or gone.
        foreach ($entry->getContextValues() as $name => $value)
        {
            if (LogContextKey::isAccountId((string)$name))
            {
                try
                {
                    $accounts[$value] = AdminUserRepository::load($value)->describe();
                }
                catch (NotFoundException)
                {
                    $accounts[$value] = '';
                }
            }
        }

        $page = new ViewLogEntryPage($entry, Settings::get()->getTimezone(), $accounts);
        return self::renderPage($response, 'Log entry', $page);
    }


    /**
     * Do something to another account (block, unblock, archive or delete it), log it, and go back to the users page
     * with what happened, or show it again with why it couldn't be done.
     *
     * @param Request $request - the request.
     *
     * @param Response $response - the response.
     *
     * @param string $userId - the account's ID.
     *
     * @param string $logMessage - what to log, e.g. "Account blocked".
     *
     * @param Closure $change - does it, given the account, and returns what to tell whoever did it. It throws an
     * InvalidInputException or AccountStateException if it can't be done.
     *
     * @return Response
     *
     * @throws NotFoundException - if there's no such account.
     */
    private function changeAccount(
        Request $request,
        Response $response,
        string $userId,
        string $logMessage,
        Closure $change
    ) : Response
    {
        $user = AdminUserRepository::load($userId);
        $isArchived = ($user->getStatus() === AccountStatus::ARCHIVED);

        try
        {
            if ($user->getId() === WebSession::getUser()->getId())
            {
                throw new InvalidInputException('You can\'t block, archive or delete your own account.');
            }

            WebSession::flash($change($user));
            $this->logAccountChange($logMessage, $user);

            // Archived accounts are only listed with "show archived accounts" on.
            $result = self::redirect($response, $isArchived ? '/admin/users?archived=1' : '/admin/users');
        }
        catch (InvalidInputException | AccountStateException $e)
        {
            $status = ($e instanceof AccountStateException) ? 409 : 400;
            $form = NewAccountForm::createEmpty();
            $result = $this->renderUsers($response, $e->getMessage(), $form, $status, $isArchived);
        }

        return $result;
    }


    /**
     * Do something to another account from its page, and go back there, saying what was done or why it couldn't be.
     *
     * @param Response $response - the response.
     *
     * @param string $userId - the account's ID.
     *
     * @param string $logMessage - what to log once it's done.
     *
     * @param Closure(AdminUser): string $change - makes the change, and returns what to tell the user.
     *
     * @return Response
     *
     * @throws NotFoundException - if there's no such account.
     */
    private function changeOnPage(Response $response, string $userId, string $logMessage, Closure $change) : Response
    {
        $user = AdminUserRepository::load($userId);

        try
        {
            if ($user->getId() === WebSession::getUser()->getId())
            {
                throw new InvalidInputException('You can\'t do this to your own account.');
            }

            WebSession::flash($change($user));
            $this->logAccountChange($logMessage, $user);
            $result = self::redirect($response, '/admin/users/' . rawurlencode($user->getId()));
        }
        catch (InvalidInputException | ConfigurationException $e)
        {
            $email = ($user->getStatus() === AccountStatus::ARCHIVED) ? '' : $user->getEmail();
            $result = self::renderUserPage($response, $user, $email, $e->getMessage(), 400);
        }

        return $result;
    }


    private function logAccountChange(string $message, AdminUser $user) : void
    {
        ServiceFactory::getLogger(LogCategory::ADMIN)->notice($message, [
            'user_id' => $user->getId(),
            'by_user_id' => WebSession::getUser()->getId(),
        ]);
    }


    // Load an account's security keys and backup codes, to count them.
    private static function loadSummary(AdminUser $user) : AdminUserSummary
    {
        $keyCount = count(SecurityKeyService::loadCredentials($user));
        return new AdminUserSummary($user, $keyCount, BackupCodeService::loadStatus($user)->getNumUnused());
    }


    private static function renderUserPage(
        Response $response,
        AdminUser $user,
        string $email,
        string $error,
        int $status
    ) : Response
    {
        $summary = self::loadSummary($user);
        $isCurrentUser = ($user->getId() === WebSession::getUser()->getId());
        $page = new ViewUserPage($summary, $isCurrentUser, $email, $error);
        return self::renderPage($response, $user->describe(), $page, $status);
    }


    /**
     * Create an account with no password, and email its owner a link to choose one. Both or neither happen: if the
     * email can't be queued (e.g. PUBLIC_URL isn't set), the account isn't created.
     *
     * @return string - what to tell whoever created it.
     *
     * @throws InvalidInputException - if the email address isn't valid or is taken, or the link can't be emailed.
     */
    private static function createAndInvite(Request $request, string $email) : string
    {
        $clientIp = (string)ClientIpResolver::resolve($request);

        try
        {
            $address = new EmailAddress($email);
            $userId = AdminUserRepository::addAccountWithoutPasswordToTransaction($address);
            $invitee = new EmailAddress(AdminUserRepository::normalise($address));
            PasswordResetService::addInvitationToTransaction($userId, $invitee, $clientIp);
            ServiceFactory::getTransaction()->commit();
        }
        catch (ConfigurationException $e)
        {
            ServiceFactory::discardTransaction();
            $msg = "The link couldn't be emailed, so the account wasn't created: {$e->getMessage()}";
            throw new InvalidInputException($msg . ' Set it up, or set a password for them instead.');
        }
        catch (Throwable $e)
        {
            ServiceFactory::discardTransaction();
            throw $e;
        }

        $user = AdminUserRepository::load($userId);
        self::logAccountCreated($user, AccountPasswordSource::EMAILED_LINK);

        return
            "The account \"{$user->getEmail()}\" was created, and emailed a link to choose its password, which works"
            . ' for ' . intdiv(PasswordResetService::INVITATION_LIFETIME_MINUTES, 60) . ' hours.';
    }


    /**
     * @return string - what to tell whoever created it.
     *
     * @throws InvalidInputException - if the email address isn't valid or is taken, or the passwords don't match or
     * don't meet the rules.
     */
    private static function createWithPassword(Request $request, string $email, bool $mustChange) : string
    {
        $password = self::getFormField($request, 'password');

        if ($password !== self::getFormField($request, 'confirm_password'))
        {
            throw new InvalidInputException("The passwords don't match.");
        }

        $user = AdminUserRepository::createWithPassword(new EmailAddress($email), $password, $mustChange);
        self::logAccountCreated($user, AccountPasswordSource::SET_NOW);

        $then = $mustChange
            ? 'They\'ll be asked to choose their own password when they first log in.'
            : 'Give them the password somewhere safe.';

        return "The account \"{$user->getEmail()}\" was created. {$then}";
    }


    private static function logAccountCreated(AdminUser $user, AccountPasswordSource $source) : void
    {
        ServiceFactory::getLogger(LogCategory::ADMIN)->notice('Account created', [
            'user_id' => $user->getId(),
            'password' => $source->value,
            'must_change_password' => $user->mustChangePassword(),
            'by_user_id' => WebSession::getUser()->getId(),
        ]);
    }


    /**
     * Show the users page.
     *
     * @param Response $response - the response.
     *
     * @param string $error - why the last action failed, or an empty string.
     *
     * @param NewAccountForm $form - what to fill the new account form with.
     *
     * @param int $status - the HTTP status.
     *
     * @param bool $isShowingArchived - whether the Archived tab is the one open.
     *
     * @return Response
     */
    private function renderUsers(
        Response $response,
        string $error,
        NewAccountForm $form,
        int $status,
        bool $isShowingArchived
    ) : Response
    {
        $summaries = [];

        foreach (AdminUserRepository::loadLive() as $user)
        {
            /* @var $user AdminUser */
            $summaries[] = self::loadSummary($user);
        }

        $users = new AdminUserSummaryCollection(...$summaries);
        $archived = AdminUserRepository::loadArchived();
        $page = new ViewUsersPage($users, $archived, $isShowingArchived, WebSession::getUser(), $form, $error);
        return self::renderPage($response, 'Users', $page, $status);
    }


    private static function renderRestorePage(
        Response $response,
        AdminUser $user,
        NewAccountForm $form,
        string $error,
        int $status
    ) : Response
    {
        $page = new ViewRestoreUserPage($user, $form, $error);
        return self::renderPage($response, 'Restore an account', $page, $status);
    }


    /**
     * Restore an archived account with no password, and email its owner a link to choose one. Both or neither happen.
     *
     * @return string - what to tell whoever restored it.
     *
     * @throws InvalidInputException - if the email address isn't valid or is taken, or the link can't be emailed.
     * @throws AccountStateException - if it isn't archived.
     */
    private static function restoreAndInvite(Request $request, AdminUser $user, string $email) : string
    {
        $clientIp = (string)ClientIpResolver::resolve($request);
        $userId = (string)$user->getId();

        try
        {
            $address = new EmailAddress($email);
            AdminUserRepository::addRestoreWithoutPasswordToTransaction($user, $address);
            $invitee = new EmailAddress(AdminUserRepository::normalise($address));
            PasswordResetService::addInvitationToTransaction($userId, $invitee, $clientIp);
            ServiceFactory::getTransaction()->commit();
        }
        catch (ConfigurationException $e)
        {
            ServiceFactory::discardTransaction();
            $msg = "The link couldn't be emailed, so the account wasn't restored: {$e->getMessage()}";
            throw new InvalidInputException($msg . ' Set it up, or set a password for them instead.');
        }
        catch (Throwable $e)
        {
            ServiceFactory::discardTransaction();
            throw $e;
        }

        $restored = AdminUserRepository::load($userId);

        return
            "The account was restored as \"{$restored->getEmail()}\", and emailed a link to choose its password, which"
            . ' works for ' . intdiv(PasswordResetService::INVITATION_LIFETIME_MINUTES, 60) . ' hours.';
    }


    /**
     * @return string - what to tell whoever restored it.
     *
     * @throws InvalidInputException - if the email address isn't valid or is taken, or the passwords don't match or
     * don't meet the rules.
     * @throws AccountStateException - if it isn't archived.
     */
    private static function restoreWithPassword(
        Request $request,
        AdminUser $user,
        string $email,
        bool $mustChange
    ) : string
    {
        $password = self::getFormField($request, 'password');

        if ($password !== self::getFormField($request, 'confirm_password'))
        {
            throw new InvalidInputException("The passwords don't match.");
        }

        $restored = AdminUserRepository::restoreWithPassword($user, new EmailAddress($email), $password, $mustChange);

        $then = $mustChange
            ? 'They\'ll be asked to choose their own password when they first log in.'
            : 'Give them the password somewhere safe.';

        return "The account was restored as \"{$restored->getEmail()}\". {$then}";
    }


    /**
     * Read the log levels to show from the query string: a list (level[]=error&level[]=critical, as the form sends
     * them) or one value of them, comma-separated (level=error,critical, as the page's own links have them). Any that
     * isn't a level (e.g. from a mistyped link) is ignored, and none means every level.
     *
     * @param mixed $value - the "level" parameter, as PHP decoded it.
     *
     * @return LogLevelCollection - in the order of LogLevel's cases, from the least severe.
     */
    private static function readLogLevels(mixed $value) : LogLevelCollection
    {
        $given = is_array($value) ? $value : explode(',', is_string($value) ? $value : '');
        $names = array_map('trim', array_filter($given, 'is_string'));
        $levels = [];

        foreach (LogLevel::cases() as $level)
        {
            if (in_array($level->value, $names, true))
            {
                $levels[] = $level;
            }
        }

        return new LogLevelCollection(...$levels);
    }
}
