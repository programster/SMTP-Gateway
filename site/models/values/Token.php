<?php

/*
 * An API key or an email token: one string of a prefix, an ID and a secret, with no separator, e.g.
 * "et_8af651aadbe3ff25" followed by 64 hex characters. The first 19 characters (the prefix and 8 random bytes in hex)
 * are the ID, the row's primary key, which is safe to show. The rest is the secret: 32 random bytes in hex, of which
 * only the SHA-256 hash is ever stored, and the token is never shown again after it's created.
 *
 * A slow password hash (bcrypt/argon2) isn't needed, as those exist to protect low-entropy, human-chosen passwords
 * from dictionary attacks, and 256 random bits can't be found that way. A fast hash also lets every SMTP login and API
 * call be checked without making the service easy to overload.
 */

declare(strict_types = 1);


final readonly class Token
{
    public const int ID_LENGTH = 19;
    public const int LENGTH = self::ID_LENGTH + 64;

    private const int ID_BYTES = 8;
    private const int SECRET_BYTES = 32;

    private TokenKind $m_kind;
    private string $m_plaintext;


    /**
     * @param TokenKind $kind - which kind it is.
     *
     * @param string $plaintext - the whole token.
     *
     * @throws InvalidTokenException - if it isn't a token of that kind, saying so if it's the other kind.
     */
    private function __construct(TokenKind $kind, string $plaintext)
    {
        $pattern = '/^[a-z]{2}_[0-9a-f]{' . (self::LENGTH - 3) . '}$/';
        $isWellFormed = (preg_match($pattern, $plaintext) === 1);

        if ($isWellFormed === false || str_starts_with($plaintext, $kind->getPrefix()) === false)
        {
            throw new InvalidTokenException(self::describeMismatch($kind, $plaintext));
        }

        $this->m_kind = $kind;
        $this->m_plaintext = $plaintext;
    }


    /**
     * Generate a new token, with a random ID and secret.
     *
     * @param TokenKind $kind - which kind to generate.
     *
     * @return Token
     */
    public static function generate(TokenKind $kind) : Token
    {
        $id = $kind->getPrefix() . bin2hex(random_bytes(self::ID_BYTES));
        return new Token($kind, $id . bin2hex(random_bytes(self::SECRET_BYTES)));
    }


    /**
     * Create a token from one a client gave, e.g. as a bearer token, or an SMTP password.
     *
     * @param string $token - the token, as given. Surrounding whitespace is ignored.
     *
     * @param TokenKind $expected - the kind it has to be.
     *
     * @return Token
     *
     * @throws InvalidTokenException - if it isn't a token of that kind, saying so if it's the other kind.
     */
    public static function createFromString(string $token, TokenKind $expected) : Token
    {
        return new Token($expected, trim($token));
    }


    /**
     * Get the hash of its secret, which is what's stored.
     * @return string - the hex-encoded SHA-256 hash.
     */
    public function getSecretHash() : string
    {
        return hash('sha256', substr($this->m_plaintext, self::ID_LENGTH));
    }


    /**
     * Whether its secret is the one whose hash is stored, compared in constant time.
     *
     * @param string $storedHash - the stored hash.
     *
     * @return bool
     */
    public function matchesHash(string $storedHash) : bool
    {
        return hash_equals($storedHash, $this->getSecretHash());
    }


    /**
     * Get its ID, e.g. "et_8af651aadbe3ff25".
     * @return string
     */
    public function getId() : string
    {
        return substr($this->m_plaintext, 0, self::ID_LENGTH);
    }


    /**
     * Describe why a string isn't a token of the kind expected: that it's the other kind, if it is, which helps
     * someone who used the wrong one, and otherwise only that it isn't one.
     *
     * @param TokenKind $expected - the kind it had to be.
     *
     * @param string $plaintext - what was given.
     *
     * @return string
     */
    private static function describeMismatch(TokenKind $expected, string $plaintext) : string
    {
        $message = "That isn't an {$expected->getLabel()}.";

        foreach (TokenKind::cases() as $kind)
        {
            if ($kind !== $expected && str_starts_with($plaintext, $kind->getPrefix()))
            {
                $message =
                    "That's an {$kind->getLabel()}, which is {$kind->getPurpose()}, not an {$expected->getLabel()}"
                    . " ({$expected->getPurpose()}).";
            }
        }

        return $message;
    }


    # Accessors
    public function getKind() : TokenKind { return $this->m_kind; }
    public function getPlaintext() : string { return $this->m_plaintext; }
}
