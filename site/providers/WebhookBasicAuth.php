<?php

/*
 * Checks webhook requests from providers that don't sign them (e.g. Postmark, Brevo, Mailjet): they're sent with
 * HTTP Basic authentication, whose password is a secret shared with the provider (the provider's webhook_secret),
 * by giving the provider a webhook address with it in, e.g. https://webhook:<secret>@smtp.example.org/webhooks/x.
 * The secret can also be sent as a bearer token ("Authorization: Bearer <secret>"), for a provider that can be set
 * up that way. Unlike a signature, it doesn't stop a request being changed on the way, so it relies on the webhook
 * being HTTPS.
 */

declare(strict_types = 1);

use Psr\Http\Message\ServerRequestInterface;


final class WebhookBasicAuth
{
    // The username in the webhook address. Only the password is checked.
    public const string USERNAME = 'webhook';

    // The shortest secret allowed, so it can't be guessed.
    public const int MIN_SECRET_LENGTH = 24;


    /**
     * Check a webhook secret is long enough to be used.
     *
     * @param string $providerName - the provider's name, for the error message.
     *
     * @param string $secret - the secret, or an empty string to leave the webhook switched off.
     *
     * @return void
     *
     * @throws ConfigurationException - if it's set, but too short.
     */
    public static function assertValidSecret(string $providerName, string $secret) : void
    {
        if ($secret !== '' && strlen($secret) < self::MIN_SECRET_LENGTH)
        {
            $msg =
                "The webhook_secret of the provider \"{$providerName}\" must be at least " . self::MIN_SECRET_LENGTH
                . ' characters long, e.g. made with "openssl rand -hex 24".';

            throw new ConfigurationException($msg);
        }
    }


    /**
     * Check whether a webhook request has the secret, as its Basic authentication password or its bearer token.
     *
     * @param ServerRequestInterface $request - the request.
     *
     * @param string $secret - the secret. An empty one never matches.
     *
     * @return bool
     */
    public static function isAuthorised(ServerRequestInterface $request, string $secret) : bool
    {
        $header = trim($request->getHeaderLine('Authorization'));
        $isBasic = (preg_match('/^Basic\s+(\S+)$/i', $header, $basic) === 1);
        $isBearer = (preg_match('/^Bearer\s+(\S+)$/i', $header, $bearer) === 1);
        $decoded = $isBasic ? base64_decode($basic[1], true) : false;
        $password = (is_string($decoded) && str_contains($decoded, ':')) ? explode(':', $decoded, 2)[1] : '';
        $given = $isBearer ? $bearer[1] : $password;
        return ($secret !== '' && $given !== '' && hash_equals($secret, $given));
    }


    /**
     * Get the address to give the provider for its webhook, with the secret in it.
     *
     * @param string $webhookUrl - the webhook's address (see ProviderWebhookUrl), e.g.
     * "https://smtp.example.org/webhooks/postmark".
     *
     * @param string $secret - the secret.
     *
     * @return string - e.g. "https://webhook:<secret>@smtp.example.org/webhooks/postmark".
     */
    public static function createUrl(string $webhookUrl, string $secret) : string
    {
        $credentials = self::USERNAME . ':' . rawurlencode($secret) . '@';
        return preg_replace('#^(https?://)#i', '${1}' . $credentials, $webhookUrl, 1);
    }


    /**
     * Get the password in an address (e.g. "https://user:password@host/"), if it has one.
     *
     * @param string $url - the address.
     *
     * @return string - the password, decoded, or an empty string if there isn't one.
     */
    public static function getPasswordInUrl(string $url) : string
    {
        $parts = parse_url($url);
        return (is_array($parts) && isset($parts['pass'])) ? rawurldecode($parts['pass']) : '';
    }


    /**
     * Get an address without any username and password in it, for comparing addresses.
     *
     * @param string $url - the address.
     *
     * @return string - e.g. "https://smtp.example.org/webhooks/postmark", without a trailing slash.
     */
    public static function removeCredentials(string $url) : string
    {
        return rtrim(preg_replace('#^(https?://)[^/@]*@#i', '$1', trim($url)), '/');
    }
}
