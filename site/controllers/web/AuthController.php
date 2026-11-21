<?php

/*
 * Logging in and out of the web interface. Logging in is the account's email address and password (or, if it's
 * switched on on the Settings page, its Google account), then (if the account has one set up) a second factor: a code
 * from an authenticator app, or a security key, or else one of its backup codes. Forgotten passwords can be reset by
 * email (see PasswordResetService).
 */

declare(strict_types = 1);

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Interfaces\RouteCollectorProxyInterface;


class AuthController extends AbstractController
{
    private const string BLOCKED_MESSAGE = 'This account has been blocked. Ask an administrator.';

    public static function registerRoutes(RouteCollectorProxyInterface $group) : void
    {
        $group->get('/login', [self::class, 'showLogin'])->setName('public.login');
        $group->post('/login', [self::class, 'login'])->setName('public.login.submit');
        $group->get('/login/second-factor', [self::class, 'showSecondFactor'])->setName('public.second-factor');
        $group->post('/login/second-factor/totp', [self::class, 'verifyTotp'])->setName('public.second-factor.totp');

        $group->post('/login/second-factor/backup-code', [self::class, 'verifyBackupCode'])
            ->setName('public.second-factor.backup-code');

        $group->post('/login/second-factor/security-key/options', [self::class, 'getSecurityKeyOptions'])
            ->setName('public.second-factor.security-key.options');

        $group->post('/login/second-factor/security-key', [self::class, 'verifySecurityKey'])
            ->setName('public.second-factor.security-key');

        $group->get('/login/google', [self::class, 'startGoogleLogin'])->setName('public.google');
        $group->get('/login/google/callback', [self::class, 'finishGoogleLogin'])->setName('public.google.callback');
        $group->get('/forgot-password', [self::class, 'showForgotPassword'])->setName('public.forgot-password');
        $group->post('/forgot-password', [self::class, 'forgotPassword'])->setName('public.forgot-password.submit');
        $group->get('/reset-password/{token}', [self::class, 'showResetPassword'])->setName('public.reset-password');

        $group->post('/reset-password/{token}', [self::class, 'resetPassword'])
            ->setName('public.reset-password.submit');

        $group->post('/logout', [self::class, 'logout'])->setName('logout');
    }


    public function showLogin(Request $request, Response $response) : Response
    {
        return WebSession::isLoggedIn()
            ? self::redirect($response, '/')
            : self::renderPage($response, 'Log in', self::createLoginPage(''));
    }


    public function login(Request $request, Response $response) : Response
    {
        $email = trim(self::getFormField($request, 'email'));
        $password = self::getFormField($request, 'password');
        $clientIpAddress = ClientIpResolver::resolve($request);
        $clientIp = (string)$clientIpAddress;
        $userId = '';

        try
        {
            $user = AdminUserRepository::loadByEmail($email);
            $userId = $user->getId();

            $checkPassword = function () use ($user, $password) : bool {
                return AdminUserRepository::verifyPassword($user, $password);
            };
        }
        catch (NotFoundException)
        {
            // Spend about the same time as checking a real password, so the response time doesn't reveal which
            // email addresses have accounts.
            $checkPassword = function () use ($password) : bool {
                password_hash($password, PASSWORD_DEFAULT);
                return false;
            };
        }

        try
        {
            $isValid = LoginThrottle::attempt($clientIpAddress, $checkPassword);

            if ($isValid === false)
            {
                // The account's ID if there is one: never the address typed, which may be anyone's.
                $context = ($userId === '') ? [] : ['user_id' => $userId];
                $context['client_ip'] = $clientIp;
                ServiceFactory::getLogger(LogCategory::ACCESS)->warning('Failed web login', $context);
                self::slowDown();
                $page = self::createLoginPage('Invalid email address or password.');
                $result = self::renderPage($response, 'Log in', $page, 401);
            }
            elseif ($user->getStatus()->canLogIn() === false)
            {
                $context = ['user_id' => $user->getId(), 'client_ip' => $clientIp];
                ServiceFactory::getLogger(LogCategory::ACCESS)->warning('Blocked account tried to log in', $context);
                $page = self::createLoginPage(self::BLOCKED_MESSAGE);
                $result = self::renderPage($response, 'Log in', $page, 403);
            }
            else
            {
                $result = $this->continueLogin($request, $response, $user, LoginMethod::PASSWORD);
            }
        }
        catch (LoginThrottledException $e)
        {
            $result = self::renderPage($response, 'Log in', self::createLoginPage($e->getMessage()), 429);
        }

        return $result;
    }


    public function startGoogleLogin(Request $request, Response $response) : Response
    {
        return Settings::get()->isGoogleSsoEnabled()
            ? self::redirect($response, GoogleLoginService::getLoginUrl())
            : self::redirect($response, '/login');
    }


    public function finishGoogleLogin(Request $request, Response $response) : Response
    {
        $clientIp = (string)ClientIpResolver::resolve($request);

        try
        {
            $login = GoogleLoginService::handleCallback($request->getQueryParams());
            $user = $login->getUser();

            if ($user->getStatus()->canLogIn() === false)
            {
                throw new InvalidInputException(self::BLOCKED_MESSAGE);
            }

            $isBypassing = match (Settings::get()->getGoogleSso()->getMfaBypass()) {
                SsoMfaBypass::NEVER => false,
                SsoMfaBypass::AUTO => $login->isMfaConfirmed(),
                SsoMfaBypass::ALWAYS => true,
            };

            $bypassing = LoginMethod::GOOGLE_BYPASSING_SECOND_FACTOR;

            $result = $isBypassing
                ? self::redirect($response, $this->completeLogin($request, $user, $bypassing))
                : $this->continueLogin($request, $response, $user, LoginMethod::GOOGLE);
        }
        catch (InvalidInputException | ConfigurationException $e)
        {
            $result = self::renderPage($response, 'Log in', self::createLoginPage($e->getMessage()), 401);
        }
        catch (NotFoundException)
        {
            $context = ['client_ip' => $clientIp];
            $logger = ServiceFactory::getLogger(LogCategory::ACCESS);
            $logger->warning('Google login for an email address with no account', $context);
            $error = 'There\'s no account for your Google account\'s email address. Ask an administrator for one.';
            $result = self::renderPage($response, 'Log in', self::createLoginPage($error), 403);
        }

        return $result;
    }


    public function showForgotPassword(Request $request, Response $response) : Response
    {
        $page = new ViewForgotPasswordPage(Settings::get()->canSendPasswordResets(), false);
        return self::renderPage($response, 'Reset your password', $page);
    }


    public function forgotPassword(Request $request, Response $response) : Response
    {
        $email = trim(self::getFormField($request, 'email'));
        $clientIp = (string)ClientIpResolver::resolve($request);
        $isEnabled = Settings::get()->canSendPasswordResets();

        if ($isEnabled)
        {
            $sendAfterResponding = function () use ($email, $clientIp) : void {
                // Respond first, so how long it takes doesn't reveal whether there's an account for the address.
                if (function_exists('fastcgi_finish_request'))
                {
                    fastcgi_finish_request();
                }

                PasswordResetService::requestReset($email, $clientIp);
            };

            register_shutdown_function($sendAfterResponding);
        }

        $page = new ViewForgotPasswordPage($isEnabled, true);
        return self::renderPage($response, 'Reset your password', $page);
    }


    public function showResetPassword(Request $request, Response $response, array $args) : Response
    {
        $token = (string)$args['token'];

        try
        {
            $user = PasswordResetService::loadUserForToken($token);
            $page = new ViewResetPasswordPage($token, $user->getEmail(), '');
            $result = self::renderPage($response, 'Choose a new password', $page);
        }
        catch (NotFoundException $e)
        {
            $result = self::renderPage($response, 'Reset your password', new ViewErrorPage(400, $e->getMessage()), 400);
        }

        // The link holds the token, so it mustn't be passed on to anywhere else in a Referer header.
        return $result->withHeader('Referrer-Policy', 'no-referrer');
    }


    public function resetPassword(Request $request, Response $response, array $args) : Response
    {
        $token = (string)$args['token'];
        $newPassword = self::getFormField($request, 'new_password');

        try
        {
            $user = PasswordResetService::loadUserForToken($token);

            try
            {
                if ($newPassword !== self::getFormField($request, 'confirm_password'))
                {
                    throw new InvalidInputException("The passwords don't match.");
                }

                PasswordResetService::resetPassword($token, $newPassword);
                $context = ['user_id' => $user->getId(), 'client_ip' => (string)ClientIpResolver::resolve($request)];
                ServiceFactory::getLogger(LogCategory::ACCESS)->notice('Password reset by email', $context);
                WebSession::logOut();
                WebSession::flash('Your password was changed. Log in with your new password.');
                $result = self::redirect($response, '/login');
            }
            catch (InvalidInputException $e)
            {
                $page = new ViewResetPasswordPage($token, $user->getEmail(), $e->getMessage());
                $result = self::renderPage($response, 'Choose a new password', $page, 400);
            }
        }
        catch (NotFoundException $e)
        {
            $result = self::renderPage($response, 'Reset your password', new ViewErrorPage(400, $e->getMessage()), 400);
        }

        return $result->withHeader('Referrer-Policy', 'no-referrer');
    }



    public function showSecondFactor(Request $request, Response $response) : Response
    {
        if (WebSession::hasPendingLogin() === false)
        {
            $result = self::redirect($response, '/login');
        }
        else
        {
            $result = self::renderSecondFactor($response, WebSession::getPendingUser(), false, '', 200);
        }

        return $result;
    }


    public function verifyTotp(Request $request, Response $response) : Response
    {
        $user = WebSession::getPendingUser();
        $code = self::getFormField($request, 'code');

        $checkCode = function () use ($user, $code) : bool {
            return TotpService::verify($user, $code);
        };

        try
        {
            if (LoginThrottle::attempt(ClientIpResolver::resolve($request), $checkCode))
            {
                $next = $this->completeLogin($request, $user, LoginMethod::AUTHENTICATOR_APP);
                $result = self::redirect($response, $next);
            }
            else
            {
                self::slowDown();
                $error = "That code isn't right, or has already been used.";
                $result = self::renderSecondFactor($response, $user, false, $error, 401);
            }
        }
        catch (LoginThrottledException $e)
        {
            $result = self::renderSecondFactor($response, $user, false, $e->getMessage(), 429);
        }

        return $result;
    }


    /**
     * Log in with a backup code instead of the second factor, using it up. The login is logged as a warning, with how
     * many codes are left, as it may mean the second factor was lost (or someone else has the codes).
     */
    public function verifyBackupCode(Request $request, Response $response) : Response
    {
        $user = WebSession::getPendingUser();
        $backupCode = self::getFormField($request, 'backup_code');

        $redeemCode = function () use ($user, $backupCode) : bool {
            return BackupCodeService::redeem($user, $backupCode);
        };

        try
        {
            $isRedeemed = LoginThrottle::attempt(ClientIpResolver::resolve($request), $redeemCode);

            if ($isRedeemed)
            {
                $numLeft = BackupCodeService::loadStatus($user)->getNumUnused();

                ServiceFactory::getLogger(LogCategory::ACCESS)->warning('Backup code used to log in', [
                    'user_id' => $user->getId(),
                    'backup_codes_left' => $numLeft,
                ]);

                // With none left, completeLogin() gives the account new ones, and goes to the page that shows them.
                $next = $this->completeLogin($request, $user, LoginMethod::BACKUP_CODE);
                $left = ($numLeft === 1) ? '1 backup code' : "{$numLeft} backup codes";

                $advice = ($numLeft <= BackupCodeStatus::RUNNING_LOW)
                    ? ' Generate new ones under "My account" before you run out.'
                    : ' If you\'ve lost your authenticator app or security key, set up a new one under "My account".';

                $message = ($numLeft === 0)
                    ? 'You logged in with your last backup code, so you have new ones below. Save them now.'
                    : "You logged in with a backup code, which can't be used again. You have {$left} left.{$advice}";

                WebSession::flash($message);
                $result = self::redirect($response, $next);
            }
            else
            {
                self::slowDown();
                $error = "That backup code isn't right, or has already been used.";
                $result = self::renderSecondFactor($response, $user, true, $error, 401);
            }
        }
        catch (LoginThrottledException $e)
        {
            $result = self::renderSecondFactor($response, $user, true, $e->getMessage(), 429);
        }

        return $result;
    }


    public function getSecurityKeyOptions(Request $request, Response $response) : Response
    {
        $user = WebSession::getPendingUser();
        return self::json($response, SecurityKeyService::getLoginOptions($user, self::getHostname($request)));
    }


    public function verifySecurityKey(Request $request, Response $response) : Response
    {
        $user = WebSession::getPendingUser();

        if (SecurityKeyService::verifyLogin($user, self::getHostname($request), self::getJsonBody($request)) === false)
        {
            self::slowDown();
            throw new ApiException(401, ErrorCodeEnum::INVALID_CREDENTIALS, "The security key couldn't be verified.");
        }

        $next = $this->completeLogin($request, $user, LoginMethod::SECURITY_KEY);
        return self::json($response, ['redirect' => $next]);
    }


    public function logout(Request $request, Response $response) : Response
    {
        WebSession::logOut();
        return self::redirect($response, '/login');
    }


    /**
     * Carry on logging in, once the password (or Google) has been checked: with a grace period if a password login
     * has one to use, on to the second factor if the account has one, or straight in if not.
     */
    private function continueLogin(
        Request $request,
        Response $response,
        AdminUser $user,
        LoginMethod $method
    ) : Response
    {
        if ($method === LoginMethod::PASSWORD && MfaGraceService::redeem($user))
        {
            $changed = AdminUserRepository::load($user->getId());
            $this->completeLogin($request, $changed, LoginMethod::PASSWORD_WITH_MFA_GRACE);

            ServiceFactory::getLogger(LogCategory::ACCESS)->warning('A grace period replaced the second factors', [
                'user_id' => $changed->getId(),
                'client_ip' => (string)ClientIpResolver::resolve($request),
            ]);

            WebSession::flash('Your old second factors have been removed. Set up a new one below.');
            $result = self::redirect($response, '/account');
        }
        elseif (AdminUserRepository::hasSecondFactor($user))
        {
            WebSession::setPendingLogin($user);
            $result = self::redirect($response, '/login/second-factor');
        }
        else
        {
            $result = self::redirect($response, $this->completeLogin($request, $user, $method));
        }

        return $result;
    }


    /**
     * Show the second factor step of logging in.
     *
     * @param Response $response - the response.
     *
     * @param AdminUser $user - the account logging in.
     *
     * @param bool $isBackupCodeOpen - whether the backup code form starts open, as it does after a backup code was
     * refused.
     *
     * @param string $error - why it was refused, or an empty string.
     *
     * @param int $status - the HTTP status.
     *
     * @return Response
     */
    private static function renderSecondFactor(
        Response $response,
        AdminUser $user,
        bool $isBackupCodeOpen,
        string $error,
        int $status
    ) : Response
    {
        $hasSecurityKeys = count(SecurityKeyService::loadCredentials($user)) > 0;
        $hasBackupCodes = BackupCodeService::loadStatus($user)->getNumUnused() > 0;
        $hasTotp = $user->hasTotp();
        $page = new ViewSecondFactorPage($hasTotp, $hasSecurityKeys, $hasBackupCodes, $isBackupCodeOpen, $error);
        return self::renderPage($response, 'Two-factor authentication', $page, $status);
    }


    private static function createLoginPage(string $error) : ViewLoginPage
    {
        $settings = Settings::get();
        return new ViewLoginPage($error, $settings->canSendPasswordResets(), $settings->isGoogleSsoEnabled());
    }


    /**
     * Log the account in, once everything needed has been checked. An account with a second factor but no unused
     * backup codes is given new ones, and sent to the page that shows them, so it can't be left without a way back
     * in if it loses its second factor.
     *
     * @param Request $request - the request.
     *
     * @param AdminUser $user - the account.
     *
     * @param LoginMethod $method - how it logged in. When it's Google bypassing the second factor, the session
     * remembers that (see WebSession::logInBypassingSecondFactor()).
     *
     * @return string - where to go next: the dashboard, or the page showing new backup codes.
     */
    private function completeLogin(Request $request, AdminUser $user, LoginMethod $method) : string
    {
        if ($method === LoginMethod::GOOGLE_BYPASSING_SECOND_FACTOR)
        {
            WebSession::logInBypassingSecondFactor($user);
        }
        else
        {
            WebSession::logIn($user);
        }

        $clientIp = ClientIpResolver::resolve($request);
        AdminUserRepository::recordLogin($user, $clientIp);
        LoginThrottle::clear($clientIp);

        ServiceFactory::getLogger(LogCategory::ACCESS)->info('Web login', [
            'user_id' => $user->getId(),
            'method' => $method->value,
            'client_ip' => (string)$clientIp,
        ]);

        return self::issueBackupCodesIfNone($user, '/');
    }


    /**
     * Wait a moment after a failed attempt, to slow down guessing.
     *
     * @return void
     */
    private static function slowDown() : void
    {
        usleep(random_int(500000, 1000000));
    }
}
