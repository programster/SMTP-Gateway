<?php

/*
 * The form backup codes take: ten lower-case letters and numbers, shown in two groups of five (e.g. "k7m2p-x9q4r"),
 * leaving out characters that are easily mixed up (0/o, 1/l/i). That's about 49 bits each, too many to guess. They're
 * read back however they're typed: in any case, and with or without the dash or spaces.
 */

declare(strict_types = 1);


final class BackupCode
{
    public const int LENGTH = 10;

    private const string ALPHABET = '23456789abcdefghjkmnpqrstuvwxyz';


    /**
     * Generate a new random code, as it's shown, e.g. "k7m2p-x9q4r".
     * @return string
     */
    public static function generate() : string
    {
        $code = (new \Random\Randomizer())->getBytesFromString(self::ALPHABET, self::LENGTH);
        return substr($code, 0, self::LENGTH / 2) . '-' . substr($code, self::LENGTH / 2);
    }


    /**
     * Get a code as it's stored and checked, however it was typed: in lower case, without the dash or any spaces.
     *
     * @param string $typed - the code, as typed, e.g. "K7M2P X9Q4R".
     *
     * @return string - e.g. "k7m2px9q4r". It may not be a valid code (see isValid()).
     */
    public static function normalise(string $typed) : string
    {
        return strtolower((string)preg_replace('/[\s-]+/', '', $typed));
    }


    /**
     * Whether a normalised code (see normalise()) is in the form a code takes, so it's worth checking against the
     * account's codes.
     *
     * @param string $normalised - the code, normalised.
     *
     * @return bool
     */
    public static function isValid(string $normalised) : bool
    {
        $pattern = '/^[' . self::ALPHABET . ']{' . self::LENGTH . '}$/';
        return preg_match($pattern, $normalised) === 1;
    }
}
