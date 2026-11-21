<?php

/*
 * Creates, loads and changes the accounts that can log in to the web interface: blocking them (they can't log in
 * until they're unblocked), archiving them (blocked, with their email address and sign-in details removed, keeping
 * only their ID, which is all the logs ever name them by), restoring archived ones, and deleting archived ones.
 */

declare(strict_types = 1);

use Programster\PgsqlObjects\CacheMode;


final class AdminUserRepository
{


    /**
     * Create an account with a password.
     *
     * @param EmailAddress $email - its email address, which it logs in with, and where password reset links go.
     *
     * @param string $password - the password, which must meet the rules set under Settings > Security.
     *
     * @param bool $mustChangePassword - whether it has to choose a password of its own when it first logs in, before
     * it can do anything else (so the one it goes on using isn't one whoever created it knows).
     *
     * @return AdminUser
     *
     * @throws InvalidInputException - if the password doesn't meet the rules (see getPasswordPolicy()), or there's
     * already an account with that email address.
     */
    public static function createWithPassword(
        EmailAddress $email,
        string $password,
        bool $mustChangePassword
    ) : AdminUser
    {
        return self::insert($email, self::hashPassword($password), $mustChangePassword);
    }


    /**
     * Create an account with a password that's already been hashed, so the password itself is never given to the
     * service (e.g. the default account, from ADMIN_PASSWORD_HASH). The password can't be checked against the rules
     * set under Settings > Security, as only its hash is known.
     *
     * @param EmailAddress $email - its email address, which it logs in with, and where password reset links go.
     *
     * @param PasswordHash $passwordHash - the hash of its password.
     *
     * @param bool $mustChangePassword - whether it has to choose a password of its own when it first logs in, before
     * it can do anything else.
     *
     * @return AdminUser
     *
     * @throws InvalidInputException - if there's already an account with that email address.
     */
    public static function createWithPasswordHash(
        EmailAddress $email,
        PasswordHash $passwordHash,
        bool $mustChangePassword
    ) : AdminUser
    {
        return self::insert($email, $passwordHash->getHash(), $mustChangePassword);
    }


    /**
     * Create an account with a password that nobody knows, for its owner to choose their own with a link (see
     * PasswordResetService::sendInvitation()). Until then, it can't be logged in to with a password.
     *
     * @param EmailAddress $email - its email address, which it logs in with, and where the link goes.
     *
     * @return AdminUser
     *
     * @throws InvalidInputException - if there's already an account with that email address.
     */
    public static function createWithoutPassword(EmailAddress $email) : AdminUser
    {
        return self::insert($email, password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT), false);
    }


    /**
     * Load an account by its email address, ignoring case.
     *
     * @param string $email - the email address, as typed (so it doesn't have to be a valid one).
     *
     * @return AdminUser
     *
     * @throws NotFoundException - if there's no such account.
     */
    public static function loadByEmail(string $email) : AdminUser
    {
        $escaped = DB::escape(mb_strtolower(trim($email)));
        $rows = DB::fetchRows("SELECT \"id\" FROM \"admin_user\" WHERE \"email\" = {$escaped}");

        if (count($rows) === 0)
        {
            throw new NotFoundException("There is no account for \"{$email}\".");
        }

        return self::load($rows[0]['id']);
    }


    public static function isEmailTaken(EmailAddress $email) : bool
    {
        $escaped = DB::escape(self::normalise($email));
        return DB::fetchValue("SELECT count(*) FROM \"admin_user\" WHERE \"email\" = {$escaped}") !== '0';
    }


    /**
     * @throws NotFoundException - if there's no such account.
     */
    public static function load(string $id) : AdminUser
    {
        try
        {
            if (Uuid::isValid($id) === false)
            {
                throw new NotFoundException("There is no account with the ID \"{$id}\".");
            }

            /* @var $user AdminUser */
            $user = AdminUserTable::getInstance()->load($id, CacheMode::IGNORE_CACHE);
        }
        catch (\Programster\PgsqlObjects\Exceptions\ExceptionNoSuchIdException)
        {
            throw new NotFoundException("There is no account with the ID \"{$id}\".");
        }

        return $user;
    }


    /**
     * Change an account's email address, which it logs in with.
     *
     * @param AdminUser $user - the account.
     *
     * @param EmailAddress $email - its new email address.
     *
     * @return AdminUser - the account, with its new email address.
     *
     * @throws InvalidInputException - if another account already has that email address.
     * @throws AccountStateException - if it's archived (see restoreWithPassword() and restoreWithoutPassword()).
     */
    public static function changeEmail(AdminUser $user, EmailAddress $email) : AdminUser
    {
        self::assertNotArchived($user);
        $normalised = self::normalise($email);

        if ($normalised !== $user->getEmail() && self::isEmailTaken($email))
        {
            throw new InvalidInputException("There's already an account for \"{$normalised}\".");
        }

        $user->update(['email' => $normalised]);
        return $user;
    }


    /**
     * Record that an account has just logged in, and from where.
     * @param AdminUser $user - the account.
     * @param IpAddress $clientIp - the IP address it logged in from.
     * @return void
     */
    public static function recordLogin(AdminUser $user, IpAddress $clientIp) : void
    {
        $query = 'UPDATE "admin_user" SET "last_login_at" = now(), "last_login_ip" = $1 WHERE "id" = $2';
        ServiceFactory::getDb()->query($query, [(string)$clientIp, $user->getId()]);
    }


    /**
     * Load the accounts that have logged in, the most recent login first.
     * @param int $limit - the most to load.
     * @return AdminUserCollection
     */
    public static function loadRecentLogins(int $limit) : AdminUserCollection
    {
        $users = [];
        $query =
            'SELECT "id" FROM "admin_user" WHERE "last_login_at" IS NOT NULL AND "status" <> \'archived\''
            . ' ORDER BY "last_login_at" DESC LIMIT ';


        foreach (DB::fetchRows($query . $limit) as $row)
        {
            $users[] = self::load($row['id']);
        }

        return new AdminUserCollection(...$users);
    }


    /**
     * Load the accounts that aren't archived (active and blocked ones), by email address.
     * @return AdminUserCollection
     */
    public static function loadLive() : AdminUserCollection
    {
        return self::loadWhere('"status" <> \'archived\' ORDER BY "email"');
    }


    /**
     * Load the archived accounts, the most recently archived first.
     * @return AdminUserCollection
     */
    public static function loadArchived() : AdminUserCollection
    {
        return self::loadWhere('"status" = \'archived\' ORDER BY "status_changed_at" DESC');
    }


    /**
     * Check an account's password. When it's right, and the hash uses outdated settings, it's rehashed.
     *
     * @param AdminUser $user - the account.
     *
     * @param string $password - the password given.
     *
     * @return bool
     */
    public static function verifyPassword(AdminUser $user, string $password) : bool
    {
        $isValid = password_verify($password, $user->getPasswordHash());

        if ($isValid && password_needs_rehash($user->getPasswordHash(), PASSWORD_DEFAULT))
        {
            $user->update(['password_hash' => password_hash($password, PASSWORD_DEFAULT)]);
        }

        return $isValid;
    }


    /**
     * Change an account's password. This logs it out everywhere it's logged in (see WebSession), and cancels any
     * password reset links sent to it.
     *
     * @param AdminUser $user - the account.
     *
     * @param string $newPassword - the new password, which must meet the rules set under Settings > Security.
     *
     * @return AdminUser - the account as it now is (with its new session version).
     *
     * @throws InvalidInputException - if the new password doesn't meet the rules (see getPasswordPolicy()).
     */
    public static function changePassword(AdminUser $user, string $newPassword) : AdminUser
    {
        return self::replacePassword($user, $newPassword, false);
    }


    /**
     * Give an active account a new random password, which it has to change when it next logs in. Like any password
     * change, this logs it out everywhere, and cancels its reset links and any grace period.
     *
     * @param AdminUser $user - the account.
     *
     * @return string - the new password, which can't be got again.
     *
     * @throws InvalidInputException - if the account is blocked or archived.
     */
    public static function setTemporaryPassword(AdminUser $user) : string
    {
        if ($user->getStatus() !== AccountStatus::ACTIVE)
        {
            throw new InvalidInputException('Only an active account can be given a temporary password.');
        }

        $password = PasswordGenerator::generate(self::getPasswordPolicy());
        self::replacePassword($user, $password, true);
        return $password;
    }


    /**
     * Whether an account has any second factor set up (TOTP or a security key).
     *
     * @param AdminUser $user - the account.
     *
     * @return bool
     */
    public static function hasSecondFactor(AdminUser $user) : bool
    {
        return $user->hasTotp() || count(SecurityKeyService::loadCredentials($user)) > 0;
    }


    /**
     * Check the password of the account doing something that needs it again (e.g. archiving another account), waiting
     * a moment if it's wrong, to slow down guessing.
     *
     * @param AdminUser $user - the account.
     *
     * @param string $password - the password given.
     *
     * @return void
     *
     * @throws InvalidInputException - if it's wrong.
     */
    public static function requirePassword(AdminUser $user, string $password) : void
    {
        if (self::verifyPassword($user, $password) === false)
        {
            usleep(random_int(500000, 1000000));
            throw new InvalidInputException('Your current password is wrong.');
        }
    }


    /**
     * Block an account, so it can't log in until it's unblocked. It's logged out everywhere, and any password reset
     * links sent to it stop working. Its API keys and email tokens (which belong to the service) keep working.
     *
     * @param AdminUser $user - the account.
     *
     * @return AdminUser - the account, as it now is.
     *
     * @throws AccountStateException - if it's archived (so already blocked), or it's the last active account.
     */
    public static function block(AdminUser $user) : AdminUser
    {
        self::assertNotArchived($user);
        self::assertNotLastActive($user);
        $id = DB::escape($user->getId());

        $block = function () use ($id) : void {
            $db = ServiceFactory::getDb();

            $db->query(
                "UPDATE \"admin_user\" SET \"status\" = 'blocked', \"status_changed_at\" = now(),"
                . " \"mfa_grace_until\" = NULL, \"session_version\" = \"session_version\" + 1 WHERE \"id\" = {$id}"
            );

            $db->query(
                "UPDATE \"password_reset_token\" SET \"used_at\" = now() WHERE \"admin_user_id\" = {$id}"
                . ' AND "used_at" IS NULL'
            );
        };

        DB::transaction($block);
        return self::load($user->getId());
    }


    /**
     * Unblock an account, so it can log in again.
     *
     * @param AdminUser $user - the account.
     *
     * @return AdminUser - the account, as it now is.
     *
     * @throws AccountStateException - if it's archived (see restoreWithPassword() and restoreWithoutPassword()).
     */
    public static function unblock(AdminUser $user) : AdminUser
    {
        self::assertNotArchived($user);
        $id = DB::escape($user->getId());
        $query = "UPDATE \"admin_user\" SET \"status\" = 'active', \"status_changed_at\" = now() WHERE \"id\" = {$id}";
        ServiceFactory::getDb()->query($query);
        return self::load($user->getId());
    }


    /**
     * Archive an account: block it, and remove its email address and everything it signs in with (its password,
     * authenticator app, security keys, backup codes and reset links), keeping only its ID, which is all the logs
     * name it by. The service's own emails to it (e.g. password reset links) have its address replaced with
     * "user <ID>", and any still queued are cancelled.
     *
     * @param AdminUser $user - the account.
     *
     * @return AdminUser - the account, as it now is.
     *
     * @throws AccountStateException - if it's already archived, or it's the last active account.
     */
    public static function archive(AdminUser $user) : AdminUser
    {
        self::assertNotArchived($user);
        self::assertNotLastActive($user);
        $id = DB::escape($user->getId());
        $email = DB::escape($user->getEmail());
        $placeholder = DB::escape('user ' . $user->getId());
        $unusablePassword = DB::escape(password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT));

        // The service's own emails to the account, matched in any case.
        $isToAccount =
            "\"source\" = 'system' AND EXISTS (SELECT 1 FROM jsonb_array_elements_text(\"recipients\") AS \"r\""
            . " WHERE lower(\"r\") = {$email})";

        $archive = function () use ($id, $email, $placeholder, $unusablePassword, $isToAccount) : void {
            $db = ServiceFactory::getDb();

            $db->query(
                "UPDATE \"admin_user\" SET \"status\" = 'archived', \"status_changed_at\" = now(), \"email\" = NULL,"
                . " \"password_hash\" = {$unusablePassword}, \"totp_secret\" = NULL, \"totp_last_used_step\" = NULL,"
                . " \"must_change_password\" = false, \"mfa_grace_until\" = NULL,"
                . " \"session_version\" = \"session_version\" + 1 WHERE \"id\" = {$id}"
            );

            foreach (['admin_webauthn_credential', 'admin_backup_code', 'password_reset_token'] as $table)
            {
                $db->query("DELETE FROM \"{$table}\" WHERE \"admin_user_id\" = {$id}");
            }

            $db->query(
                "UPDATE \"email_message\" SET \"status\" = 'cancelled', \"raw_message_base64\" = NULL"
                . " WHERE \"status\" = 'queued' AND {$isToAccount}"
            );

            $db->query(
                "UPDATE \"email_event\" SET \"recipient\" = {$placeholder} WHERE lower(\"recipient\") = {$email}"
                . " AND \"email_message_id\" IN (SELECT \"id\" FROM \"email_message\" WHERE {$isToAccount})"
            );

            $db->query(
                "UPDATE \"email_message\" SET \"recipients\" = (SELECT jsonb_agg(CASE WHEN lower(\"r\") = {$email}"
                . " THEN {$placeholder} ELSE \"r\" END) FROM jsonb_array_elements_text(\"recipients\") AS \"r\")"
                . " WHERE {$isToAccount}"
            );
        };

        DB::transaction($archive);
        return self::load($user->getId());
    }


    /**
     * Restore an archived account with a password, keeping its ID (so its logs are still its own).
     *
     * @param AdminUser $user - the archived account.
     *
     * @param EmailAddress $email - its email address, which archiving removed.
     *
     * @param string $password - its password, which must meet the rules set under Settings > Security.
     *
     * @param bool $mustChangePassword - whether it has to choose a password of its own when it first logs in.
     *
     * @return AdminUser - the account, as it now is.
     *
     * @throws AccountStateException - if it isn't archived.
     * @throws InvalidInputException - if the password doesn't meet the rules, or the address is taken.
     */
    public static function restoreWithPassword(
        AdminUser $user,
        EmailAddress $email,
        string $password,
        bool $mustChangePassword
    ) : AdminUser
    {
        return self::restore($user, $email, self::hashPassword($password), $mustChangePassword);
    }


    /**
     * Restore an archived account with a password nobody knows, for its owner to choose their own with a link (see
     * PasswordResetService::sendInvitation()). It keeps its ID (so its logs are still its own).
     *
     * @param AdminUser $user - the archived account.
     *
     * @param EmailAddress $email - its email address, which archiving removed.
     *
     * @return AdminUser - the account, as it now is.
     *
     * @throws AccountStateException - if it isn't archived.
     * @throws InvalidInputException - if the address is taken.
     */
    public static function restoreWithoutPassword(AdminUser $user, EmailAddress $email) : AdminUser
    {
        return self::restore($user, $email, password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT), false);
    }


    /**
     * Delete an archived account for good, with every row linked to it (they're deleted with it). Its log entries
     * keep its ID, which then shows as a deleted account. Only an archived account can be deleted, so a delete
     * always goes through archiving first.
     *
     * @param AdminUser $user - the account.
     *
     * @return void
     *
     * @throws AccountStateException - if it isn't archived.
     */
    public static function delete(AdminUser $user) : void
    {
        if ($user->getStatus() !== AccountStatus::ARCHIVED)
        {
            $msg =
                'Only an archived account can be deleted. Archive it first, then delete it from the archived'
                . ' accounts.';

            throw new AccountStateException(AccountStateProblem::NOT_ARCHIVED, $msg);
        }

        $user->delete();
    }


    /**
     * Get the rules a password must meet, as set under Settings > Security, for checking one and describing them.
     * @return PasswordPolicy
     */
    public static function getPasswordPolicy() : PasswordPolicy
    {
        return Settings::get()->getSecurity()->getPasswordPolicy();
    }


    /**
     * Replace an account's password, logging it out everywhere, and cancelling its reset links and any grace period.
     *
     * @param AdminUser $user - the account.
     *
     * @param string $newPassword - the new password, which must meet the rules.
     *
     * @param bool $mustChange - whether it has to choose another when it next logs in.
     *
     * @return AdminUser - the account as it now is.
     *
     * @throws InvalidInputException - if the new password doesn't meet the rules.
     */
    private static function replacePassword(AdminUser $user, string $newPassword, bool $mustChange) : AdminUser
    {
        $hash = self::hashPassword($newPassword);
        $id = DB::escape($user->getId());

        $change = function () use ($user, $hash, $id, $mustChange) : void {
            $changes = ['password_hash' => $hash, 'session_version' => $user->getSessionVersion() + 1];

            // A grace period ends here, so a reset link can't be combined with one to get past the second factor.
            $user->update($changes + ['must_change_password' => $mustChange, 'mfa_grace_until' => null]);

            ServiceFactory::getDb()->query(
                "UPDATE \"password_reset_token\" SET \"used_at\" = now() WHERE \"admin_user_id\" = {$id}"
                . ' AND "used_at" IS NULL'
            );
        };

        DB::transaction($change);
        return self::load($user->getId());
    }


    /**
     * Load the accounts matching a condition.
     * @param string $condition - the WHERE condition, and any ORDER BY, already escaped.
     * @return AdminUserCollection
     */
    private static function loadWhere(string $condition) : AdminUserCollection
    {
        $users = [];

        foreach (DB::fetchRows('SELECT "id" FROM "admin_user" WHERE ' . $condition) as $row)
        {
            $users[] = self::load($row['id']);
        }

        return new AdminUserCollection(...$users);
    }


    /**
     * @throws AccountStateException - if the account is archived.
     */
    private static function assertNotArchived(AdminUser $user) : void
    {
        if ($user->getStatus() === AccountStatus::ARCHIVED)
        {
            $msg = 'That account is archived. Restore it first.';
            throw new AccountStateException(AccountStateProblem::ARCHIVED, $msg);
        }
    }


    /**
     * Check that taking an account out of use (blocking or archiving it) would leave at least one that can log in,
     * so nobody is ever locked out of the web interface.
     *
     * @throws AccountStateException - if it's the last active account.
     */
    private static function assertNotLastActive(AdminUser $user) : void
    {
        $others = DB::escape($user->getId());
        $query = "SELECT count(*) FROM \"admin_user\" WHERE \"status\" = 'active' AND \"id\" <> {$others}";

        if ((int)DB::fetchValue($query) === 0)
        {
            $msg = 'That\'s the last account that can log in, so it can\'t be blocked or archived.';
            throw new AccountStateException(AccountStateProblem::LAST_ACTIVE_ACCOUNT, $msg);
        }
    }


    /**
     * @throws AccountStateException - if the account isn't archived.
     * @throws InvalidInputException - if the email address is taken.
     */
    private static function restore(
        AdminUser $user,
        EmailAddress $email,
        string $passwordHash,
        bool $mustChangePassword
    ) : AdminUser
    {
        if ($user->getStatus() !== AccountStatus::ARCHIVED)
        {
            $msg = 'Only an archived account can be restored.';
            throw new AccountStateException(AccountStateProblem::NOT_ARCHIVED, $msg);
        }

        if (self::isEmailTaken($email))
        {
            throw new InvalidInputException("There is already an account for {$email}.");
        }

        $user->update([
            'status' => AccountStatus::ACTIVE->value,
            'status_changed_at' => Time::now(),
            'email' => self::normalise($email),
            'password_hash' => $passwordHash,
            'must_change_password' => $mustChangePassword,
        ]);

        return self::load($user->getId());
    }


    /**
     * @throws InvalidInputException - if there's already an account with that email address.
     */
    private static function insert(EmailAddress $email, string $passwordHash, bool $mustChangePassword) : AdminUser
    {
        $address = self::normalise($email);

        if (self::isEmailTaken($email))
        {
            throw new InvalidInputException("There is already an account for {$address}.");
        }

        /* @var $user AdminUser */
        $user = AdminUserTable::getInstance()->insert([
            'email' => $address,
            'password_hash' => $passwordHash,
            'totp_secret' => null,
            'totp_last_used_step' => null,
            'created_at' => Time::now(),
            'last_login_at' => null,
            'last_login_ip' => null,
            'must_change_password' => $mustChangePassword,
        ]);

        return $user;
    }


    /**
     * Get an email address in the form accounts are stored and matched by: all lower case.
     */
    private static function normalise(EmailAddress $email) : string
    {
        return mb_strtolower((string)$email);
    }


    /**
     * @throws InvalidInputException - if the password doesn't meet the rules set under Settings > Security.
     */
    private static function hashPassword(string $password) : string
    {
        self::getPasswordPolicy()->check($password);
        return password_hash($password, PASSWORD_DEFAULT);
    }
}
