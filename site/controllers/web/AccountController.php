<?php

/*
 * The logged-in person's own account: their password, their second factors (an authenticator app, and security keys),
 * and their backup codes, for when their second factors are lost. Removing a second factor, or generating new backup
 * codes, needs the account's password, so someone who finds a logged-in browser can't quietly weaken the account. An
 * account is given its first backup codes when it sets up its first second factor.
 */

declare(strict_types = 1);

use Programster\Http\HttpCode;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Interfaces\RouteCollectorProxyInterface;


class AccountController extends AbstractController
{
    public static function registerRoutes(RouteCollectorProxyInterface $group) : void
    {
        $group->get('/account', [self::class, 'show'])->setName('account');
        $group->post('/account/password', [self::class, 'changePassword'])->setName('account.password');
        $group->get('/account/totp', [self::class, 'showTotpSetup'])->setName('account.totp');
        $group->post('/account/totp', [self::class, 'enableTotp'])->setName('account.totp.enable');
        $group->post('/account/totp/remove', [self::class, 'removeTotp'])->setName('account.totp.remove');

        $group->post('/account/security-keys/options', [self::class, 'getSecurityKeyOptions'])
            ->setName('account.security-keys.options');

        $group->post('/account/security-keys', [self::class, 'registerSecurityKey'])
            ->setName('account.security-keys.register');

        $group->post('/account/security-keys/{id}/remove', [self::class, 'removeSecurityKey'])
            ->setName('account.security-keys.remove');

        $group->get('/account/backup-codes', [self::class, 'showBackupCodes'])->setName('account.backup-codes');

        $group->post('/account/backup-codes', [self::class, 'regenerateBackupCodes'])
            ->setName('account.backup-codes.regenerate');
    }


    public function show(Request $request, Response $response) : Response
    {
        return $this->renderAccount($response, '', HttpCode::OK);
    }


    public function changePassword(Request $request, Response $response) : Response
    {
        $user = WebSession::getUser();
        $newPassword = self::getFormField($request, 'new_password');

        try
        {
            self::requirePassword($request, $user);

            if ($newPassword !== self::getFormField($request, 'confirm_password'))
            {
                throw new InvalidInputException("The new passwords don't match.");
            }

            WebSession::keepAfterPasswordChange(AdminUserRepository::changePassword($user, $newPassword));
            ServiceFactory::getLogger(LogCategory::ACCESS)->notice('Password changed', ['user_id' => $user->getId()]);
            WebSession::flash('Your password was changed.');
            $result = self::redirect($response, '/account');
        }
        catch (InvalidInputException $e)
        {
            $result = $this->renderAccount($response, $e->getMessage(), HttpCode::BAD_REQUEST);
        }

        return $result;
    }


    public function showTotpSetup(Request $request, Response $response) : Response
    {
        $secret = TotpService::generateSecret();
        WebSession::setPendingTotpSecret($secret);
        return $this->renderTotpSetup($response, $secret, '', HttpCode::OK);
    }


    public function enableTotp(Request $request, Response $response) : Response
    {
        $user = WebSession::getUser();
        $secret = WebSession::getPendingTotpSecret();

        try
        {
            $enabledUser = TotpService::enable($user, $secret, self::getFormField($request, 'code'));
            WebSession::clearPendingTotpSecret();
            $logger = ServiceFactory::getLogger(LogCategory::ACCESS);
            $logger->notice('Authenticator app set up', ['user_id' => $user->getId()]);
            WebSession::flash('Your authenticator app is set up. You will need a code from it each time you log in.');
            $result = self::redirect($response, self::issueBackupCodesIfNone($enabledUser, '/account'));
        }
        catch (InvalidInputException $e)
        {
            $result = $this->renderTotpSetup($response, $secret, $e->getMessage(), HttpCode::BAD_REQUEST);
        }

        return $result;
    }


    public function removeTotp(Request $request, Response $response) : Response
    {
        $user = WebSession::getUser();

        try
        {
            self::requirePassword($request, $user);
            self::assertNotLastSecondFactor($user, 1, 0);
            TotpService::disable($user);
            self::deleteBackupCodesIfUnprotected($user);
            $logger = ServiceFactory::getLogger(LogCategory::ACCESS);
            $logger->notice('Authenticator app removed', ['user_id' => $user->getId()]);
            WebSession::flash('Your authenticator app was removed.');
            $result = self::redirect($response, '/account');
        }
        catch (InvalidInputException $e)
        {
            $result = $this->renderAccount($response, $e->getMessage(), HttpCode::BAD_REQUEST);
        }

        return $result;
    }


    public function getSecurityKeyOptions(Request $request, Response $response) : Response
    {
        $options = SecurityKeyService::getRegistrationOptions(WebSession::getUser(), self::getHostname($request));
        return self::json($response, $options);
    }


    public function registerSecurityKey(Request $request, Response $response) : Response
    {
        $user = WebSession::getUser();
        $body = self::getJsonBody($request);
        $name = (string)($body['name'] ?? '');
        $credential = SecurityKeyService::register($user, self::getHostname($request), $name, $body);

        ServiceFactory::getLogger(LogCategory::ACCESS)->notice('Security key registered', [
            'user_id' => $user->getId(),
            'key_name' => $credential->getName(),
        ]);

        WebSession::flash("The security key \"{$credential->getName()}\" was registered.");
        $body = ['id' => $credential->getId(), 'redirect' => self::issueBackupCodesIfNone($user, '/account')];
        return self::json($response, $body, HttpCode::CREATED);
    }


    /**
     * Show the backup codes just generated, once: they're forgotten as they're shown, and only their hashes are
     * stored. With none to show, this goes back to the account page.
     */
    public function showBackupCodes(Request $request, Response $response) : Response
    {
        $codes = WebSession::takeNewBackupCodes();
        $user = WebSession::getUser();

        $result = (count($codes) === 0)
            ? self::redirect($response, '/account')
            : self::renderPage(
                $response,
                'Your backup codes',
                new ViewBackupCodesPage(self::getSiteUrl($request), $user->getEmail(), ...$codes)
            );

        return $result;
    }


    /**
     * Generate a new set of backup codes, replacing the old ones, which stop working.
     */
    public function regenerateBackupCodes(Request $request, Response $response) : Response
    {
        $user = WebSession::getUser();

        try
        {
            self::requirePassword($request, $user);

            if (AdminUserRepository::hasSecondFactor($user) === false)
            {
                $msg = 'Backup codes are for when your second factor is lost, so set one up first.';
                throw new InvalidInputException($msg);
            }

            WebSession::setNewBackupCodes(...BackupCodeService::replace($user));
            $logger = ServiceFactory::getLogger(LogCategory::ACCESS);
            $logger->notice('Backup codes generated, replacing any old ones', ['user_id' => $user->getId()]);
            $result = self::redirect($response, '/account/backup-codes');
        }
        catch (InvalidInputException $e)
        {
            $result = $this->renderAccount($response, $e->getMessage(), HttpCode::BAD_REQUEST);
        }

        return $result;
    }


    public function removeSecurityKey(Request $request, Response $response, array $args) : Response
    {
        $user = WebSession::getUser();

        try
        {
            self::requirePassword($request, $user);
            self::assertNotLastSecondFactor($user, 0, 1);
            SecurityKeyService::remove($user, $args['id']);
            self::deleteBackupCodesIfUnprotected($user);
            $logger = ServiceFactory::getLogger(LogCategory::ACCESS);
            $logger->notice('Security key removed', ['user_id' => $user->getId()]);
            WebSession::flash('The security key was removed.');
            $result = self::redirect($response, '/account');
        }
        catch (InvalidInputException $e)
        {
            $result = $this->renderAccount($response, $e->getMessage(), HttpCode::BAD_REQUEST);
        }

        return $result;
    }


    /**
     * Delete an account's backup codes once it has no second factor left, as there's nothing for them to stand in
     * for. It's given new ones when it sets one up again.
     *
     * @param AdminUser $user - the account.
     *
     * @return void
     */
    private static function deleteBackupCodesIfUnprotected(AdminUser $user) : void
    {
        if (AdminUserRepository::hasSecondFactor(AdminUserRepository::load($user->getId())) === false)
        {
            BackupCodeService::deleteAll($user);
        }
    }


    /**
     * @throws InvalidInputException - if the "current_password" field isn't the account's password.
     */
    private static function requirePassword(Request $request, AdminUser $user) : void
    {
        if (AdminUserRepository::verifyPassword($user, self::getFormField($request, 'current_password')) === false)
        {
            usleep(random_int(500000, 1000000));
            throw new InvalidInputException('Your current password is wrong.');
        }
    }


    /**
     * When two-factor authentication is required, check that removing a second factor would leave the account with
     * at least one.
     *
     * @param AdminUser $user - the account.
     *
     * @param int $totpToRemove - 1 if the authenticator app is being removed, otherwise 0.
     *
     * @param int $securityKeysToRemove - how many security keys are being removed.
     *
     * @return void
     *
     * @throws InvalidInputException - if it would leave the account with none.
     */
    private static function assertNotLastSecondFactor(
        AdminUser $user,
        int $totpToRemove,
        int $securityKeysToRemove
    ) : void
    {
        $remaining =
            ($user->hasTotp() ? 1 : 0) - $totpToRemove
            + count(SecurityKeyService::loadCredentials($user)) - $securityKeysToRemove;

        if (Settings::get()->isMfaRequired() && $remaining < 1)
        {
            $msg =
                'Two-factor authentication is required, so your last second factor can\'t be removed. Set up another'
                . ' one first (an authenticator app or a security key), then remove this one.';

            throw new InvalidInputException($msg);
        }
    }


    private function renderAccount(Response $response, string $error, HttpCode $status) : Response
    {
        $user = WebSession::getUser();
        $isMfaRequired = Settings::get()->isMfaRequired();
        $keys = SecurityKeyService::loadCredentials($user);
        $page = new ViewAccountPage($user, $keys, BackupCodeService::loadStatus($user), $isMfaRequired, $error);
        return self::renderPage($response, 'My account', $page, $status);
    }


    private function renderTotpSetup(Response $response, string $secret, string $error, HttpCode $status) : Response
    {
        $uri = TotpService::getProvisioningUri(WebSession::getUser(), $secret);
        $page = new ViewTotpSetupPage(TotpService::getQrCodeDataUri($uri), $secret, $error);
        return self::renderPage($response, 'Set up an authenticator app', $page, $status);
    }
}
