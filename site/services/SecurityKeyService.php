<?php

/*
 * Security keys (e.g. YubiKeys) as a second factor, through WebAuthn. Registering a key and logging in with one are
 * both two steps: the server gives the browser a challenge (getRegistrationOptions/getLoginOptions), the browser
 * has the key sign it, and the server checks the result (register/verifyLogin). The challenge is kept in the
 * session between the two, so each can only be answered once.
 *
 * Binary values pass to and from the browser as base64url strings.
 */

declare(strict_types = 1);

use Programster\PgsqlObjects\CacheMode;
use lbuchs\WebAuthn\Binary\ByteBuffer;
use lbuchs\WebAuthn\WebAuthn;
use lbuchs\WebAuthn\WebAuthnException;


final class SecurityKeyService
{
    private const int TIMEOUT_SECONDS = 60;


    /**
     * Get the options for the browser's navigator.credentials.create(), to register a new key.
     *
     * @param AdminUser $user - the account the key is for.
     *
     * @param string $hostname - the hostname the site is being used at (the WebAuthn "relying party ID"). A key is
     * tied to it, and only works on that hostname.
     *
     * @return stdClass - the options, ready to be returned as JSON.
     */
    public static function getRegistrationOptions(AdminUser $user, string $hostname) : stdClass
    {
        $existingIds = [];

        foreach (self::loadCredentials($user) as $credential)
        {
            /* @var $credential AdminWebauthnCredential */
            $existingIds[] = ByteBuffer::fromBase64Url($credential->getCredentialId());
        }

        $webAuthn = self::createWebAuthn($hostname);

        $options = $webAuthn->getCreateArgs(
            userId: hex2bin(str_replace('-', '', $user->getId())),
            userName: $user->getEmail(),
            userDisplayName: $user->getEmail(),
            timeout: self::TIMEOUT_SECONDS,
            requireResidentKey: false,
            requireUserVerification: false,
            crossPlatformAttachment: null,
            excludeCredentialIds: $existingIds,
        );

        WebSession::setWebAuthnChallenge($webAuthn->getChallenge()->getBinaryString());
        return $options;
    }


    /**
     * Check the browser's response to the registration options, and store the new key.
     *
     * @param AdminUser $user - the account the key is for.
     *
     * @param string $hostname - the hostname the site is being used at.
     *
     * @param string $name - a name to recognise the key by, e.g. "Blue YubiKey".
     *
     * @param array $response - the browser's response: "clientDataJSON" and "attestationObject", base64url encoded.
     *
     * @return AdminWebauthnCredential
     *
     * @throws InvalidInputException - if the response is invalid, or the name is empty.
     */
    public static function register(
        AdminUser $user,
        string $hostname,
        string $name,
        array $response
    ) : AdminWebauthnCredential
    {
        $name = trim($name);

        if ($name === '' || mb_strlen($name) > 100)
        {
            throw new InvalidInputException('The security key needs a name, of up to 100 characters.');
        }

        try
        {
            $data = self::createWebAuthn($hostname)->processCreate(
                clientDataJSON: self::decodeField($response, 'clientDataJSON'),
                attestationObject: self::decodeField($response, 'attestationObject'),
                challenge: WebSession::takeWebAuthnChallenge(),
                requireUserVerification: false,
                requireUserPresent: true,
                failIfRootMismatch: false,
            );
        }
        catch (WebAuthnException $e)
        {
            throw new InvalidInputException("The security key couldn't be registered: {$e->getMessage()}");
        }

        /* @var $credential AdminWebauthnCredential */
        $credential = AdminWebauthnCredentialTable::getInstance()->insert([
            'admin_user_id' => $user->getId(),
            'name' => $name,
            'credential_id' => self::base64UrlEncode($data->credentialId),
            'public_key_pem' => $data->credentialPublicKey,
            'signature_counter' => (int)($data->signatureCounter ?? 0),
            'created_at' => Time::now(),
            'last_used_at' => null,
        ]);

        return $credential;
    }


    /**
     * Get the options for the browser's navigator.credentials.get(), to log in with one of an account's keys.
     *
     * @param AdminUser $user - the account logging in.
     *
     * @param string $hostname - the hostname the site is being used at.
     *
     * @return stdClass - the options, ready to be returned as JSON.
     */
    public static function getLoginOptions(AdminUser $user, string $hostname) : stdClass
    {
        $ids = [];

        foreach (self::loadCredentials($user) as $credential)
        {
            /* @var $credential AdminWebauthnCredential */
            $ids[] = ByteBuffer::fromBase64Url($credential->getCredentialId());
        }

        $webAuthn = self::createWebAuthn($hostname);
        $timeout = self::TIMEOUT_SECONDS;
        $options = $webAuthn->getGetArgs(credentialIds: $ids, timeout: $timeout, requireUserVerification: false);
        WebSession::setWebAuthnChallenge($webAuthn->getChallenge()->getBinaryString());
        return $options;
    }


    /**
     * Check the browser's response to the login options.
     *
     * @param AdminUser $user - the account logging in.
     *
     * @param string $hostname - the hostname the site is being used at.
     *
     * @param array $response - the browser's response: "id", "clientDataJSON", "authenticatorData" and
     * "signature", base64url encoded.
     *
     * @return bool - true if one of the account's keys signed the challenge.
     */
    public static function verifyLogin(AdminUser $user, string $hostname, array $response) : bool
    {
        $isValid = false;
        $credentialId = (string)($response['id'] ?? '');
        $challenge = WebSession::takeWebAuthnChallenge();

        foreach (self::loadCredentials($user) as $credential)
        {
            /* @var $credential AdminWebauthnCredential */
            if ($isValid === false && hash_equals($credential->getCredentialId(), $credentialId))
            {
                try
                {
                    $webAuthn = self::createWebAuthn($hostname);

                    $webAuthn->processGet(
                        clientDataJSON: self::decodeField($response, 'clientDataJSON'),
                        authenticatorData: self::decodeField($response, 'authenticatorData'),
                        signature: self::decodeField($response, 'signature'),
                        credentialPublicKey: $credential->getPublicKeyPem(),
                        challenge: $challenge,
                        prevSignatureCnt: $credential->getSignatureCounter(),
                        requireUserVerification: false,
                        requireUserPresent: true,
                    );

                    $counter = $webAuthn->getSignatureCounter() ?? $credential->getSignatureCounter();
                    $credential->update(['signature_counter' => (int)$counter, 'last_used_at' => Time::now()]);
                    $isValid = true;
                }
                catch (WebAuthnException | InvalidInputException $e)
                {
                    ServiceFactory::getLogger(LogCategory::ACCESS)->warning('Security key login failed', [
                        'user_id' => $user->getId(),
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        return $isValid;
    }


    public static function loadCredentials(AdminUser $user) : AdminWebauthnCredentialCollection
    {
        $id = DB::escape($user->getId());
        $credentials = [];
        $query =
            "SELECT \"id\" FROM \"admin_webauthn_credential\" WHERE \"admin_user_id\" = {$id}"
            . ' ORDER BY "created_at"';


        foreach (DB::fetchRows($query) as $row)
        {
            $credentials[] = AdminWebauthnCredentialTable::getInstance()->load($row['id'], CacheMode::IGNORE_CACHE);
        }

        return new AdminWebauthnCredentialCollection(...$credentials);
    }


    /**
     * Remove one of an account's keys.
     *
     * @param AdminUser $user - the account.
     *
     * @param string $credentialId - the ID of the key's record (not its WebAuthn credential ID).
     *
     * @return void
     *
     * @throws NotFoundException - if the account has no such key.
     */
    public static function remove(AdminUser $user, string $credentialId) : void
    {
        $found = false;

        foreach (self::loadCredentials($user) as $credential)
        {
            /* @var $credential AdminWebauthnCredential */
            if ($credential->getId() === $credentialId)
            {
                $credential->delete();
                $found = true;
            }
        }

        if ($found === false)
        {
            throw new NotFoundException('There is no such security key on this account.');
        }
    }


    private static function createWebAuthn(string $hostname) : WebAuthn
    {
        return new WebAuthn(SERVICE_NAME, $hostname, null, true);
    }


    /**
     * @throws InvalidInputException - if the field is missing or isn't base64url.
     */
    private static function decodeField(array $response, string $field) : string
    {
        $value = $response[$field] ?? null;

        if (!is_string($value) || preg_match('/^[A-Za-z0-9_-]+$/', $value) !== 1)
        {
            throw new InvalidInputException("The security key's response is missing \"{$field}\".");
        }

        return ByteBuffer::fromBase64Url($value)->getBinaryString();
    }


    private static function base64UrlEncode(string $binary) : string
    {
        return rtrim(strtr(base64_encode($binary), '+/', '-_'), '=');
    }
}
