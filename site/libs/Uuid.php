<?php

declare(strict_types = 1);


final class Uuid
{
    /**
     * Check whether a string is a UUID, before it's used to look something up (which would otherwise make
     * PostgreSQL throw an error, rather than simply find nothing).
     *
     * @param string $value - the possible UUID.
     *
     * @return bool
     */
    public static function isValid(string $value) : bool
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value) === 1;
    }


    /**
     * Generate a new random (version 4) UUID, in lowercase, e.g. "0b7f1c2e-5a3d-4e8f-9b6a-2c4d6e8f0a1b".
     * @return string
     */
    public static function generateV4() : string
    {
        $bytes = random_bytes(16);
        // The version (4) goes in the top 4 bits of byte 6, and the variant (10xx) in the top 2 bits of byte 8.
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);

        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) . '-' . substr($hex, 16, 4)
            . '-' . substr($hex, 20, 12);
    }
}
