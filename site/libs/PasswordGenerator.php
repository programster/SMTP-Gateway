<?php

/*
 * Generates random passwords that meet any of the rules that can be set under Settings > Security: 20 characters (or
 * the minimum length, if that's more), with at least one capital letter, number and symbol, leaving out characters
 * that are easily mixed up (0/O, 1/l/I), as someone may have to read one out or retype it.
 */

declare(strict_types = 1);


final class PasswordGenerator
{
    private const int DEFAULT_LENGTH = 20;
    private const string LOWER = 'abcdefghijkmnopqrstuvwxyz';
    private const string CAPITALS = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
    private const string NUMBERS = '23456789';
    private const string SYMBOLS = '!@#%*-_+=?';


    /**
     * Generate a random password.
     * @param PasswordPolicy $policy - the rules it has to meet.
     * @return string
     */
    public static function generate(PasswordPolicy $policy) : string
    {
        $length = max(self::DEFAULT_LENGTH, $policy->getMinLength());
        $characters = [self::pick(self::CAPITALS), self::pick(self::NUMBERS), self::pick(self::SYMBOLS)];

        while (count($characters) < $length)
        {
            $characters[] = self::pick(self::LOWER . self::CAPITALS . self::NUMBERS);
        }

        // Shuffle them (Fisher-Yates, with a secure random source), so the capital, number and symbol can be anywhere.
        for ($i = count($characters) - 1; $i > 0; $i--)
        {
            $j = random_int(0, $i);
            [$characters[$i], $characters[$j]] = [$characters[$j], $characters[$i]];
        }

        return implode('', $characters);
    }


    private static function pick(string $alphabet) : string
    {
        return $alphabet[random_int(0, strlen($alphabet) - 1)];
    }
}
