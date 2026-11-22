<?php

/*
 * Logging in to the web interface with Google (when it's switched on, on the Settings page), using
 * programster/google-sso. Only existing accounts can log in this way: the Google account's email address has to match
 * an account's, and Google has to have verified it. It replaces the password, but not the second factor: an account
 * with one still has to give it.
 *
 * The package checks the state, the ID token's signature, expiry, issuer and audience, and refuses an email address
 * Google hasn't verified.
 */

declare(strict_types = 1);

use Programster\GoogleSso\Exceptions\ExceptionCsrfTokenMismatch;
use Programster\GoogleSso\Exceptions\ExceptionGoogleLoginError;
use Programster\GoogleSso\Exceptions\ExceptionGoogleSso;
use Programster\GoogleSso\Exceptions\ExceptionMissingCallbackParameter;
use Programster\GoogleSso\Exceptions\ExceptionUnverifiedEmail;
use Programster\GoogleSso\GoogleSsoClient;
use Programster\GoogleSso\MfaPolicyEnum;
use Programster\GoogleSso\NativeSessionCsrfTokenStore;
use Psr\Http\Client\ClientExceptionInterface;


final class GoogleLoginService
{
    /**
     * Get the URL to send someone to, to log in with Google. It stores a random state in the session, which Google
     * sends back, so the callback can't be forged.
     *
     * @return string
     *
     * @throws ConfigurationException - if logging in with Google isn't enabled.
     */
    public static function getLoginUrl() : string
    {
        return self::createClient()->createGoogleSsoLoginUrl();
    }


    /**
     * Handle Google sending someone back after they've logged in, and find their account.
     *
     * @param array $query - the callback's query parameters: "code" and "state", or "error" if they didn't log in.
     *
     * @return GoogleLogin - the account whose email address matches their Google account's, and whether Google
     * confirmed they used two-step verification.
     *
     * @throws InvalidInputException - if they didn't log in, or Google's response couldn't be trusted. The message is
     * safe to show them.
     *
     * @throws NotFoundException - if there's no account for their email address.
     *
     * @throws ConfigurationException - if logging in with Google isn't enabled.
     */
    public static function handleCallback(array $query) : GoogleLogin
    {
        try
        {
            $googleUser = self::createClient()->handleGoogleSsoCallback($query);
        }
        catch (ExceptionGoogleLoginError | ExceptionMissingCallbackParameter)
        {
            throw new InvalidInputException('Logging in with Google was cancelled, or didn\'t work.');
        }
        catch (ExceptionUnverifiedEmail $e)
        {
            $logger = ServiceFactory::getLogger(LogCategory::ACCESS);

            $logger->warning('Google login refused: the email address isn\'t verified', [
                'error' => $e->getMessage(),
            ]);

            $msg = 'Your Google account\'s email address isn\'t verified, so you weren\'t logged in.';
            throw new InvalidInputException($msg);
        }
        catch (ExceptionCsrfTokenMismatch)
        {
            // This includes ExceptionMissingCsrfToken, for a session that has expired since the login started.
            throw new InvalidInputException('That Google login had expired, or didn\'t start here. Please try again.');
        }
        catch (ExceptionGoogleSso | UnexpectedValueException | DomainException | ClientExceptionInterface $e)
        {
            // Google's response was an error, or the ID token was invalid, expired or not issued for this client (the
            // last three are from php-jwt), or Google couldn't be reached.
            $logger = ServiceFactory::getLogger(LogCategory::ACCESS);
            $logger->warning('Google login failed', ['error' => $e->getMessage()]);
            throw new InvalidInputException('Google\'s response couldn\'t be checked, so you weren\'t logged in.');
        }

        $user = AdminUserRepository::loadByEmail($googleUser->getEmail());
        return new GoogleLogin($user, $googleUser->hasMultiFactorAuthentication());
    }


    /**
     * @throws ConfigurationException - if logging in with Google isn't enabled.
     */
    private static function createClient() : GoogleSsoClient
    {
        $settings = Settings::get()->createGoogleSsoSettings();

        // REPORT asks Google how they signed in (the "amr" claim), without refusing anyone who didn't use MFA.
        return new GoogleSsoClient(
            clientId: $settings->getClientId(),
            clientSecret: $settings->getClientSecret(),
            googleCallbackUrl: $settings->getCallbackUrl(),
            requestFactory: new \GuzzleHttp\Psr7\HttpFactory(),
            responseFactory: new \GuzzleHttp\Psr7\HttpFactory(),
            httpClient: new \GuzzleHttp\Client(['timeout' => 10]),
            csrfTokenStore: new NativeSessionCsrfTokenStore(WebSession::GOOGLE_SSO_STATE_KEY),
            mfaPolicy: MfaPolicyEnum::REPORT
        );
    }
}
