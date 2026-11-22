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
        $randomizer = new \Random\Randomizer();

        $required = [
            $randomizer->getBytesFromString(self::CAPITALS, 1),
            $randomizer->getBytesFromString(self::NUMBERS, 1),
            $randomizer->getBytesFromString(self::SYMBOLS, 1),
        ];

        $alphabet = self::LOWER . self::CAPITALS . self::NUMBERS;
        $rest = $randomizer->getBytesFromString($alphabet, $length - count($required));

        // Shuffled, so the capital, number and symbol can be anywhere.
        return implode('', $randomizer->shuffleArray([...$required, ...str_split($rest)]));
    }
}
