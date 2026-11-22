<?php

/*
 * Resets forgotten web interface passwords by email. Someone asks for a reset for an email address, and if there's
 * an account for it, a link with a random token is emailed to that address. The link lets them choose a new
 * password, once, within an hour. Only the token's SHA-256 hash is stored, so a copy of the database can't be used
 * to reset anyone's password.
 *
 * A reset only replaces the password: logging in afterwards still needs the account's second factor, if it has one,
 * so someone who can read the account's email can't get in with that alone. Changing the password logs the account
 * out everywhere, and cancels any other links sent to it.
 *
 * Whether an account exists for an address is never revealed: the response is the same either way, and the email is
 * sent after the response (see AuthController), so the time it takes doesn't give it away either.
 */

declare(strict_types = 1);

use Programster\PgsqlLib\Exceptions\ExceptionQueryError;
use Programster\PgsqlLib\Query;


final class PasswordResetService
{
    // How long a link works for.
    public const int TOKEN_LIFETIME_MINUTES = 60;

    // How long the link sent to a new account, to choose its first password, works for: long enough to be seen.
    public const int INVITATION_LIFETIME_MINUTES = 72 * 60;

    // The least time between two links sent to one account, so nobody can flood someone's inbox with them.
    public const int MIN_SECONDS_BETWEEN_EMAILS = 300;

    private const string INVALID_LINK_MESSAGE =
        'This password reset link isn\'t valid any more. It may have expired, or already been used.';


    /**
     * Send a password reset link to an account's email address, if there's an account for it, and one hasn't been
     * sent to it in the last MIN_SECONDS_BETWEEN_EMAILS. What happened is logged, never shown to whoever asked.
     *
     * @param string $email - the email address, as typed.
     *
     * @param string $clientIp - the IP address of whoever asked.
     *
     * @return void
     */
    public static function requestReset(string $email, string $clientIp) : void
    {
        // The account's ID, never the address asked for, which may be anyone's.
        $context = ['client_ip' => $clientIp];
        $logger = ServiceFactory::getLogger(LogCategory::ACCESS);

        try
        {
            $user = AdminUserRepository::loadByEmail($email);
            $context['user_id'] = $user->getId();

            if ($user->getStatus()->canLogIn() === false)
            {
                $logger->notice('Password reset not sent, as the account is blocked', $context);
            }
            elseif (self::wasEmailedRecently($user))
            {
                $msg = 'Password reset not sent, as one was sent in the last few minutes';
                $logger->notice($msg, $context);
            }
            else
            {
                self::sendResetLink($user, $clientIp);
                $logger->notice('Password reset link queued', $context);
            }
        }
        catch (NotFoundException)
        {
            $logger->notice('Password reset asked for an email address with no account', $context);
        }
        catch (ConfigurationException | InvalidInputException $e)
        {
            $context['error'] = $e->getMessage();
            $logger->error('Password reset link couldn\'t be queued', $context);
        }
    }


    /**
     * Add the email inviting a new account to choose its own password to the transaction being built, along with its
     * link, which works for INVITATION_LIFETIME_MINUTES. It's the same kind of link as a password reset's.
     *
     * @param string $userId - the account's ID.
     *
     * @param EmailAddress $email - the account's email address.
     *
     * @param string $clientIp - the IP address of whoever created it.
     *
     * @return void
     *
     * @throws ConfigurationException - if PUBLIC_URL, or the address the service's own emails are sent from, isn't set.
     */
    public static function addInvitationToTransaction(string $userId, EmailAddress $email, string $clientIp) : void
    {
        $hours = intdiv(self::INVITATION_LIFETIME_MINUTES, 60);
        $siteUrl = Config::get()->getPublicUrl();

        $writeText = function (string $link) use ($hours, $siteUrl) : string {
            return
                "An account has been made for you on the SMTP gateway at {$siteUrl}, which you log in to with this"
                . " email address.\r\n\r\n"
                . "To choose your password, open this link within {$hours} hours. It only works once:\r\n\r\n"
                . "{$link}\r\n\r\n"
                . "If you weren't expecting this, you can ignore this email.\r\n";
        };

        $minutes = self::INVITATION_LIFETIME_MINUTES;
        self::addLinkToTransaction($userId, $email, $clientIp, $minutes, 'Choose your password', $writeText);
    }


    /**
     * Find the account a password reset link is for.
     *
     * @param string $token - the token from the link.
     *
     * @return AdminUser
     *
     * @throws NotFoundException - if the link isn't valid, has expired, or has already been used.
     */
    public static function loadUserForToken(string $token) : AdminUser
    {
        $rows = DB::fetchRows(
            'SELECT "admin_user_id" FROM "password_reset_token" WHERE "token_hash" = ' . DB::escape(self::hash($token))
            . ' AND "used_at" IS NULL AND "expires_at" > now()'
        );

        if (count($rows) === 0)
        {
            throw new NotFoundException(self::INVALID_LINK_MESSAGE);
        }

        return AdminUserRepository::load($rows[0]['admin_user_id']);
    }


    /**
     * Set a new password with a password reset link. The link can't be used again, and the account is logged out
     * everywhere.
     *
     * @param string $token - the token from the link.
     *
     * @param string $newPassword - the new password.
     *
     * @return AdminUser - the account.
     *
     * @throws NotFoundException - if the link isn't valid, has expired, or has already been used.
     *
     * @throws InvalidInputException - if the new password doesn't meet the rules set under Settings > Security.
     */
    public static function resetPassword(string $token, string $newPassword) : AdminUser
    {
        $user = self::loadUserForToken($token);
        $hash = DB::escape(self::hash($token));

        // Claiming the link first locks its row, so if it's used twice at once, the second finds it already used, and
        // the whole transaction fails.
        $claimLink = DB::generateRequireRowQuery(
            "UPDATE \"password_reset_token\" SET \"used_at\" = now() WHERE \"token_hash\" = {$hash}"
            . ' AND "used_at" IS NULL AND "expires_at" > now()'
        );

        try
        {
            ServiceFactory::getTransaction()->append($claimLink);
            AdminUserRepository::addPasswordChangeToTransaction($user, $newPassword, false);
            ServiceFactory::getTransaction()->commit();
        }
        catch (ExceptionQueryError $exception)
        {
            ServiceFactory::discardTransaction();
            throw DB::isNoRowError($exception) ? new NotFoundException(self::INVALID_LINK_MESSAGE) : $exception;
        }
        catch (Throwable $exception)
        {
            ServiceFactory::discardTransaction();
            throw $exception;
        }

        return AdminUserRepository::load((string)$user->getId());
    }


    /**
     * Email an account a link to choose a new password, because an administrator asked to. Unlike someone asking on
     * the login page, whoever asked can be told when it wasn't sent, and why.
     *
     * @param AdminUser $user - the account.
     *
     * @param string $clientIp - the IP address of the administrator.
     *
     * @return void
     *
     * @throws InvalidInputException - if it can't log in, or was sent a link in the last few minutes.
     * @throws ConfigurationException - if PUBLIC_URL, or the address the service's own emails are sent from, isn't set.
     */
    public static function sendResetFromAdmin(AdminUser $user, string $clientIp) : void
    {
        if ($user->getStatus()->canLogIn() === false)
        {
            throw new InvalidInputException('A blocked or archived account can\'t be sent a password reset link.');
        }

        if (self::wasEmailedRecently($user))
        {
            throw new InvalidInputException('This account was sent a link in the last few minutes. Try again later.');
        }

        $minutes = self::TOKEN_LIFETIME_MINUTES;
        $siteUrl = Config::get()->getPublicUrl();

        $writeText = function (string $link) use ($minutes, $siteUrl) : string {
            return
                "An administrator of the SMTP gateway at {$siteUrl} has sent you this link to set a new password for"
                . " your account.\r\n\r\n"
                . "To choose a new password, open this link within {$minutes} minutes. It only works once:\r\n\r\n"
                . "{$link}\r\n\r\n"
                . "You'll still need your second factor (authenticator app or security key) to log in"
                . " afterwards.\r\n\r\n"
                . "If you weren't expecting this, you can ignore this email: your password hasn't been changed.\r\n";
        };

        self::sendLink($user, $clientIp, $minutes, 'Reset your password', $writeText);
    }


    private static function wasEmailedRecently(AdminUser $user) : bool
    {
        $seconds = self::MIN_SECONDS_BETWEEN_EMAILS;

        $query =
            'SELECT count(*) FROM "password_reset_token" WHERE "admin_user_id" = ' . DB::escape($user->getId())
            . " AND \"created_at\" > now() - make_interval(secs => {$seconds})";

        return DB::fetchValue($query) !== '0';
    }


    /**
     * @throws ConfigurationException - if PUBLIC_URL, or the address the service's own emails are sent from, isn't set.
     * @throws InvalidInputException - if the account's email address isn't valid.
     */
    private static function sendResetLink(AdminUser $user, string $clientIp) : void
    {
        $minutes = self::TOKEN_LIFETIME_MINUTES;
        $siteUrl = Config::get()->getPublicUrl();

        $writeText = function (string $link) use ($minutes, $siteUrl, $clientIp) : string {
            return
                "Someone (hopefully you) asked to reset the password for your account on the SMTP gateway at"
                . " {$siteUrl}.\r\n\r\n"
                . "To choose a new password, open this link within {$minutes} minutes. It only works once:\r\n\r\n"
                . "{$link}\r\n\r\n"
                . "You'll still need your second factor (authenticator app or security key) to log in"
                . " afterwards.\r\n\r\n"
                . "If you didn't ask for this, you can ignore this email: your password hasn't been changed. The"
                . " request came from the IP address {$clientIp}.\r\n";
        };

        self::sendLink($user, $clientIp, $minutes, 'Reset your password', $writeText);
    }


    /**
     * Create a token for an existing account, cancelling any it already has, and queue the email with the link to it,
     * together. If the email can't be queued, the token isn't created, so another can be asked for straight away.
     *
     * @param AdminUser $user - the account.
     *
     * @param string $clientIp - the IP address of whoever asked for it.
     *
     * @param int $minutes - how long the link works for.
     *
     * @param string $subject - the email's subject.
     *
     * @param Closure(string): string $writeText - writes the email's text, given the link.
     *
     * @return void
     *
     * @throws ConfigurationException - if PUBLIC_URL, or the address the service's own emails are sent from, isn't set.
     * @throws InvalidInputException - if the account's email address isn't valid.
     */
    private static function sendLink(
        AdminUser $user,
        string $clientIp,
        int $minutes,
        string $subject,
        Closure $writeText
    ) : void
    {
        try
        {
            $email = new EmailAddress($user->getEmail());
            self::addLinkToTransaction((string)$user->getId(), $email, $clientIp, $minutes, $subject, $writeText);
            ServiceFactory::getTransaction()->commit();
        }
        catch (Throwable $exception)
        {
            ServiceFactory::discardTransaction();
            throw $exception;
        }
    }


    /**
     * Add a new token for an account to the transaction being built, cancelling any it already has, along with the
     * email with the link to it (which has the system priority, so it goes ahead of everything else).
     *
     * @param string $userId - the account's ID.
     *
     * @param EmailAddress $email - where to send the link.
     *
     * @param string $clientIp - the IP address of whoever asked for it.
     *
     * @param int $minutes - how long the link works for.
     *
     * @param string $subject - the email's subject.
     *
     * @param Closure(string): string $writeText - writes the email's text, given the link.
     *
     * @return void
     *
     * @throws ConfigurationException - if PUBLIC_URL, or the address the service's own emails are sent from, isn't set.
     */
    private static function addLinkToTransaction(
        string $userId,
        EmailAddress $email,
        string $clientIp,
        int $minutes,
        string $subject,
        Closure $writeText
    ) : void
    {
        $token = bin2hex(random_bytes(32));
        $link = Config::get()->getPublicUrl() . '/reset-password/' . $token;

        $cancelOthers = new Query(
            'UPDATE "password_reset_token" SET "used_at" = now() WHERE "admin_user_id" = $1 AND "used_at" IS NULL',
            [$userId]
        );

        $insertToken = new Query(
            'INSERT INTO "password_reset_token" ("id", "admin_user_id", "token_hash", "client_ip", "expires_at")'
            . " VALUES (uuidv7(), \$1, \$2, \$3, now() + make_interval(mins => {$minutes}))",
            [$userId, self::hash($token), $clientIp]
        );

        ServiceFactory::getTransaction()->append($cancelOthers, $insertToken);
        SystemMailer::addToTransaction($email, $subject, $writeText($link), $clientIp);
    }


    private static function hash(string $token) : string
    {
        return hash('sha256', $token);
    }
}
