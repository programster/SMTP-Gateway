<?php

/*
 * A password hash made by PHP's password_hash(), for setting an account's password without the password itself ever
 * being given to the service, such as the default account's (ADMIN_PASSWORD_HASH). It's checked when it's made, so a
 * plain password, or anything else password_verify() can't check against, can never be stored as one. Its error
 * messages never include the value given, in case it's a plain password.
 */

declare(strict_types = 1);


readonly class PasswordHash
{
    private string $m_hash;


    /**
     * @param string $hash - the hash, exactly as password_hash() returns it, e.g. "$2y$12$...".
     * @throws InvalidInputException - if it isn't a password hash that password_verify() can check against.
     */
    public function __construct(string $hash)
    {
        if (password_get_info($hash)['algo'] === null)
        {
            $msg = 'That isn\'t a password hash made by password_hash(), so a password could never match it.';
            throw new InvalidInputException($msg);
        }

        $this->m_hash = $hash;
    }


    /**
     * Create one from a password hash that's been base64 encoded, as it is in .env, so that the "$" signs in it can't
     * be taken for variables by Docker Compose (or by GitLab, in a CI/CD variable).
     *
     * @param string $encoded - the base64 encoded hash, e.g. from
     * base64_encode(password_hash('the password', PASSWORD_DEFAULT)). Surrounding whitespace is ignored.
     *
     * @return PasswordHash
     *
     * @throws InvalidInputException - if it isn't base64, or it doesn't decode to a password hash.
     */
    public static function createFromBase64(string $encoded) : PasswordHash
    {
        $decoded = base64_decode(trim($encoded), true);

        if ($decoded === false)
        {
            $msg = 'That isn\'t base64 encoded. It should be base64_encode(password_hash(...)), not the password.';
            throw new InvalidInputException($msg);
        }

        return new PasswordHash($decoded);
    }


    # Accessors
    public function getHash() : string { return $this->m_hash; }
}
