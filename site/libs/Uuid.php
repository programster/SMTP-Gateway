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
}
