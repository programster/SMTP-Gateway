<?php

/*
 * Checks the email token a client sends email with, and that it may be used from the client's IP address. Over HTTP
 * it's a bearer token. Over SMTP the password is the token, and the username is the token's ID (its first 19
 * characters), as SMTP needs a username too. Every rejection is recorded.
 */

declare(strict_types = 1);


final class EmailTokenAuthenticator
{
    /**
     * Check the username and password an SMTP client logged in with.
     *
     * @param string $username - the username, which must be the token's ID.
     *
     * @param string $password - the password, which is the whole token.
     *
     * @param IpAddress $clientIp - where the client is connecting from.
     *
     * @return AuthenticatedEmailToken
     *
     * @throws RequestRejectedException - if the username isn't the token's ID, the token is wrong or revoked, or it
     * may not be used from the client's IP address. The rejection is recorded.
     */
    public static function authenticateSmtpLogin(
        string $username,
        string $password,
        IpAddress $clientIp
    ) : AuthenticatedEmailToken
    {
        $password = trim($password);
        $idLength = Token::ID_LENGTH;

        if (strtolower(trim($username)) !== strtolower(substr($password, 0, $idLength)))
        {
            $msg =
                "The username must be the email token's ID, which is its first {$idLength} characters (starting"
                . ' "' . TokenKind::EMAIL_TOKEN->getPrefix() . '"), and the password the whole token.';

            $rejection = new RequestRejectedException(RejectionReason::INVALID_CREDENTIALS, $msg);
            RejectionLog::record('', MessageSource::SMTP, $clientIp, $rejection);
            throw $rejection;
        }

        return self::authenticate($password, $clientIp, MessageSource::SMTP);
    }


    /**
     * Check an email token a client gave.
     *
     * @param string $token - the token, as given.
     *
     * @param IpAddress $clientIp - where the client is connecting from.
     *
     * @param MessageSource $source - how the client is connecting.
     *
     * @return AuthenticatedEmailToken
     *
     * @throws RequestRejectedException - if the token is wrong (or is an API key, which it says), revoked, or may not
     * be used from the client's IP address. The rejection is recorded.
     */
    public static function authenticate(
        string $token,
        IpAddress $clientIp,
        MessageSource $source
    ) : AuthenticatedEmailToken
    {
        $knownTokenId = '';

        try
        {
            $given = Token::createFromString($token, TokenKind::EMAIL_TOKEN);
            $emailToken = EmailTokenRepository::load($given->getId());
            $knownTokenId = $emailToken->getId();

            if ($given->matchesHash($emailToken->getSecretHash()) === false)
            {
                throw new RequestRejectedException(RejectionReason::INVALID_CREDENTIALS, 'Invalid credentials.');
            }

            if ($emailToken->isRevoked())
            {
                $msg = 'This email token has been revoked.';
                throw new RequestRejectedException(RejectionReason::REVOKED_EMAIL_TOKEN, $msg);
            }

            $policy = EmailTokenRepository::loadPolicy($emailToken);

            if ($policy->getAllowedNetworks()->allows($clientIp) === false)
            {
                $msg = "This email token may not be used from {$clientIp}.";
                throw new RequestRejectedException(RejectionReason::IP_NOT_ALLOWED, $msg);
            }

            EmailTokenRepository::markUsed($emailToken, $clientIp);
        }
        catch (InvalidTokenException $e)
        {
            // Malformed, or an API key given instead, which the message says.
            $rejection = new RequestRejectedException(RejectionReason::INVALID_CREDENTIALS, $e->getMessage());
            RejectionLog::record('', $source, $clientIp, $rejection);
            throw $rejection;
        }
        catch (NotFoundException)
        {
            // The same message as a wrong secret, so the response doesn't reveal which IDs exist.
            $rejection = new RequestRejectedException(RejectionReason::INVALID_CREDENTIALS, 'Invalid credentials.');
            RejectionLog::record('', $source, $clientIp, $rejection);
            throw $rejection;
        }
        catch (RequestRejectedException $rejection)
        {
            RejectionLog::record($knownTokenId, $source, $clientIp, $rejection);
            throw $rejection;
        }

        return new AuthenticatedEmailToken($emailToken, $policy, $clientIp, $source);
    }
}
