<?php

/*
 * The web interface's session: who is logged in, who is part way through logging in (has given their password,
 * but not yet their second factor), the CSRF token, and the other short-lived values kept between requests.
 * Everything goes through here, so no other code touches $_SESSION or its keys.
 */

declare(strict_types = 1);


final class WebSession
{
    // Where programster/google-sso keeps the state it sends to Google. It reads and writes it itself, so this is the
    // one session key used outside this class.
    public const string GOOGLE_SSO_STATE_KEY = 'google_sso_state';

    // How long someone has, after giving their password, to give their second factor.
    private const int PENDING_LOGIN_SECONDS = 300;

    // How long a session lasts without being used.
    private const int IDLE_TIMEOUT_SECONDS = 8 * 3600;


    /**
     * Start the session, with a secure cookie, and end it if it has been idle too long.
     *
     * @param bool $isHttps - whether the request came over HTTPS, in which case the cookie is only ever sent over
     * HTTPS.
     *
     * @return void
     */
    public static function start(bool $isHttps) : void
    {
        session_name('smtp_api_session');

        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => $isHttps,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        ini_set('session.use_strict_mode', '1');
        session_start();

        if (isset($_SESSION['last_active']) && time() - (int)$_SESSION['last_active'] > self::IDLE_TIMEOUT_SECONDS)
        {
            $_SESSION = [];
        }

        $_SESSION['last_active'] = time();
    }


    /**
     * Whether someone is logged in: their account still exists, and its password hasn't been changed since they
     * logged in. If either isn't so, they're logged out.
     *
     * @return bool
     */
    public static function isLoggedIn() : bool
    {
        $isLoggedIn = false;

        if (isset($_SESSION['user_id']))
        {
            try
            {
                self::getUser();
                $isLoggedIn = true;
            }
            catch (NotFoundException)
            {
                $isLoggedIn = false;
            }
        }

        return $isLoggedIn;
    }


    /**
     * Get the account that's logged in.
     *
     * @return AdminUser
     *
     * @throws NotFoundException - if nobody is logged in, or their account has been deleted, blocked or archived, or
     * its password changed, since they logged in (any of which logs them out).
     */
    public static function getUser() : AdminUser
    {
        if (isset($_SESSION['user_id']) === false)
        {
            throw new NotFoundException('Nobody is logged in.');
        }

        try
        {
            $user = AdminUserRepository::load((string)$_SESSION['user_id']);
        }
        catch (NotFoundException $e)
        {
            self::logOut();
            throw $e;
        }

        if ($user->getStatus()->canLogIn() === false)
        {
            $msg = 'Your account has been blocked. Ask an administrator.';
            self::logOut();
            self::flash($msg, true);
            throw new NotFoundException($msg);
        }

        if ($user->getSessionVersion() !== (int)($_SESSION['session_version'] ?? -1))
        {
            $msg = 'Your password was changed, so please log in again.';
            self::logOut();
            self::flash($msg, true);
            throw new NotFoundException($msg);
        }

        return $user;
    }


    /**
     * Log an account in, once every factor has been checked.
     *
     * @param AdminUser $user - the account.
     *
     * @return void
     */
    public static function logIn(AdminUser $user) : void
    {
        // A new session ID, so one planted before logging in (session fixation) is useless.
        session_regenerate_id(true);
        unset($_SESSION['pending_user_id'], $_SESSION['pending_since'], $_SESSION['webauthn_challenge']);
        unset($_SESSION['entered_provider_passwords']);
        $_SESSION['user_id'] = $user->getId();
        $_SESSION['session_version'] = $user->getSessionVersion();
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }


    /**
     * Log an account in with Google, without its second factor, as the Settings page says Google "always" skips it.
     * The session remembers this, so requiring a second factor doesn't make it set one up either.
     *
     * @param AdminUser $user - the account.
     *
     * @return void
     */
    public static function logInBypassingSecondFactor(AdminUser $user) : void
    {
        self::logIn($user);
        $_SESSION['second_factor_bypassed'] = true;
    }


    /**
     * Whether this session logged in with Google without a second factor, as the Settings page allowed it.
     *
     * @return bool
     */
    public static function hasBypassedSecondFactor() : bool
    {
        return ($_SESSION['second_factor_bypassed'] ?? false) === true;
    }


    /**
     * Keep this session logged in after its own account's password was changed in it, which logs out every other
     * session (see AdminUserRepository::changePassword()).
     *
     * @param AdminUser $user - the account, as it is after the change.
     *
     * @return void
     */
    public static function keepAfterPasswordChange(AdminUser $user) : void
    {
        session_regenerate_id(true);
        $_SESSION['session_version'] = $user->getSessionVersion();
    }


    public static function logOut() : void
    {
        $_SESSION = [];
        session_regenerate_id(true);
    }


    /**
     * Record that an account has given the right password, and now needs to give its second factor.
     *
     * @param AdminUser $user - the account.
     *
     * @return void
     */
    public static function setPendingLogin(AdminUser $user) : void
    {
        session_regenerate_id(true);
        $_SESSION['pending_user_id'] = $user->getId();
        $_SESSION['pending_since'] = time();
    }


    public static function hasPendingLogin() : bool
    {
        return (
               isset($_SESSION['pending_user_id'], $_SESSION['pending_since'])
            && time() - (int)$_SESSION['pending_since'] <= self::PENDING_LOGIN_SECONDS
        );
    }


    /**
     * Get the account that's part way through logging in.
     *
     * @return AdminUser
     *
     * @throws NotFoundException - if nobody is, they took too long, or the account has been blocked or archived since.
     */
    public static function getPendingUser() : AdminUser
    {
        if (self::hasPendingLogin() === false)
        {
            throw new NotFoundException('Your login has expired. Please log in again.');
        }

        $user = AdminUserRepository::load((string)$_SESSION['pending_user_id']);

        if ($user->getStatus()->canLogIn() === false)
        {
            throw new NotFoundException('Your account has been blocked. Ask an administrator.');
        }

        return $user;
    }


    /**
     * Get the state a Google login was started with (see GoogleSsoStateStore).
     * @return string - the state, or an empty string if there isn't one (which no callback will match).
     */
    public static function getGoogleSsoState() : string
    {
        return (string)($_SESSION[self::GOOGLE_SSO_STATE_KEY] ?? '');
    }


    public static function setGoogleSsoState(string $state) : void
    {
        $_SESSION[self::GOOGLE_SSO_STATE_KEY] = $state;
    }


    public static function removeGoogleSsoState() : void
    {
        unset($_SESSION[self::GOOGLE_SSO_STATE_KEY]);
    }


    /**
     * Get the CSRF token, which every form and JSON request that changes something must send back.
     *
     * @return string
     */
    public static function getCsrfToken() : string
    {
        if (!isset($_SESSION['csrf_token']))
        {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }

        return (string)$_SESSION['csrf_token'];
    }


    public static function isValidCsrfToken(string $token) : bool
    {
        return $token !== '' && hash_equals(self::getCsrfToken(), $token);
    }


    public static function setWebAuthnChallenge(string $challenge) : void
    {
        $_SESSION['webauthn_challenge'] = base64_encode($challenge);
    }


    /**
     * Get the WebAuthn challenge, and forget it, so it can only be answered once.
     *
     * @return string - the challenge, or an empty string if there isn't one (which no response will match).
     */
    public static function takeWebAuthnChallenge() : string
    {
        $challenge = base64_decode((string)($_SESSION['webauthn_challenge'] ?? ''));
        unset($_SESSION['webauthn_challenge']);
        return $challenge;
    }


    /**
     * Keep the TOTP secret being set up, until it's confirmed with a code.
     *
     * @param string $secret - the secret.
     *
     * @return void
     */
    public static function setPendingTotpSecret(string $secret) : void
    {
        $_SESSION['pending_totp_secret'] = $secret;
    }


    /**
     * @throws NotFoundException - if no TOTP set up is in progress.
     */
    public static function getPendingTotpSecret() : string
    {
        if (!isset($_SESSION['pending_totp_secret']))
        {
            throw new NotFoundException('Please start setting up the authenticator app again.');
        }

        return (string)$_SESSION['pending_totp_secret'];
    }


    public static function clearPendingTotpSecret() : void
    {
        unset($_SESSION['pending_totp_secret']);
    }


    /**
     * Keep a new set of backup codes, just generated, until the page that shows them once (see takeNewBackupCodes()).
     *
     * @param string ...$codes - the codes, as they're shown.
     *
     * @return void
     */
    public static function setNewBackupCodes(string ...$codes) : void
    {
        $_SESSION['new_backup_codes'] = $codes;
    }


    /**
     * Get the backup codes kept by setNewBackupCodes(), and forget them, so they're only ever shown once.
     * @return list<string> - the codes, or an empty list if there aren't any to show.
     */
    public static function takeNewBackupCodes() : array
    {
        $codes = array_values(array_map('strval', (array)($_SESSION['new_backup_codes'] ?? [])));
        unset($_SESSION['new_backup_codes']);
        return $codes;
    }


    /**
     * Remember that this session entered a custom provider's password, so it can change the server the password's
     * sent to without entering it again (e.g. to fix a typo in the host). Only this session is trusted with that: any
     * other has to enter the password again (see SmtpSettings::findServerChanges()). It's forgotten when the session
     * logs out, or another account logs in.
     *
     * @param string $providerName - the provider's name, as it was saved.
     *
     * @return void
     */
    public static function rememberEnteredProviderPassword(string $providerName) : void
    {
        $_SESSION['entered_provider_passwords'][$providerName] = true;
    }


    /**
     * Whether this session entered a custom provider's password (see rememberEnteredProviderPassword()).
     * @param string $providerName - the provider's name.
     * @return bool
     */
    public static function hasEnteredProviderPassword(string $providerName) : bool
    {
        return ($_SESSION['entered_provider_passwords'][$providerName] ?? false) === true;
    }


    /**
     * Keep a message to show on the next page, e.g. "The API key was revoked."
     *
     * @param string $message - the message.
     *
     * @param bool $isError - whether it's an error, rather than a success.
     *
     * @return void
     */
    public static function flash(string $message, bool $isError = false) : void
    {
        $_SESSION['flash'] = ['message' => $message, 'is_error' => $isError];
    }


    /**
     * Get the message kept for this page, and forget it.
     *
     * @return FlashMessage - an empty one if there isn't a message.
     */
    public static function takeFlash() : FlashMessage
    {
        $flash = $_SESSION['flash'] ?? ['message' => '', 'is_error' => false];
        unset($_SESSION['flash']);
        return new FlashMessage((string)$flash['message'], (bool)$flash['is_error']);
    }
}
