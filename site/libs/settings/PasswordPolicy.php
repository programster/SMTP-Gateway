<?php

/*
 * What a web interface account's password must have (set under Settings > Security): at least a number of characters,
 * and, optionally, a capital letter, a number and a symbol. It's checked whenever a password is set: when an account
 * is created with one, its password is changed, or it's reset. Existing passwords aren't checked again, so tightening
 * the rules only applies to the next password each account sets.
 */

declare(strict_types = 1);


final readonly class PasswordPolicy
{
    public const int LOWEST_MIN_LENGTH = 8;

    // Passwords are hashed with bcrypt, which only uses the first 72 bytes, so a longer minimum would mean nothing.
    public const int HIGHEST_MIN_LENGTH = 64;

    private int $m_minLength;
    private bool $m_requiresCapital;
    private bool $m_requiresNumber;
    private bool $m_requiresSymbol;


    /**
     * @param int $minLength - the fewest characters, from LOWEST_MIN_LENGTH to HIGHEST_MIN_LENGTH.
     *
     * @param bool $requiresCapital - whether it must have a capital letter (A to Z, or any other upper case letter).
     *
     * @param bool $requiresNumber - whether it must have a digit.
     *
     * @param bool $requiresSymbol - whether it must have something that's neither a letter nor a digit, e.g. "!" or a
     * space.
     *
     * @throws InvalidInputException - if the minimum length is outside the range.
     */
    public function __construct(int $minLength, bool $requiresCapital, bool $requiresNumber, bool $requiresSymbol)
    {
        if ($minLength < self::LOWEST_MIN_LENGTH || $minLength > self::HIGHEST_MIN_LENGTH)
        {
            $low = self::LOWEST_MIN_LENGTH;
            $high = self::HIGHEST_MIN_LENGTH;
            throw new InvalidInputException("A password's minimum length must be from {$low} to {$high} characters.");
        }

        $this->m_minLength = $minLength;
        $this->m_requiresCapital = $requiresCapital;
        $this->m_requiresNumber = $requiresNumber;
        $this->m_requiresSymbol = $requiresSymbol;
    }


    /**
     * Create the rules used when none are set: at least 12 characters, of any kind, as length matters far more than
     * the mix of characters.
     *
     * @return PasswordPolicy
     */
    public static function createDefault() : PasswordPolicy
    {
        return new PasswordPolicy(12, false, false, false);
    }


    /**
     * Check a password meets the rules.
     *
     * @param string $password - the password.
     *
     * @return void
     *
     * @throws InvalidInputException - if it doesn't, saying everything it's missing.
     */
    public function check(string $password) : void
    {
        $missing = [];

        if (mb_strlen($password) < $this->m_minLength)
        {
            $missing[] = "at least {$this->m_minLength} characters";
        }

        if ($this->m_requiresCapital && preg_match('/\p{Lu}/u', $password) !== 1)
        {
            $missing[] = 'a capital letter';
        }

        if ($this->m_requiresNumber && preg_match('/\p{N}/u', $password) !== 1)
        {
            $missing[] = 'a number';
        }

        if ($this->m_requiresSymbol && preg_match('/[^\p{L}\p{N}]/u', $password) !== 1)
        {
            $missing[] = 'a symbol';
        }

        if (count($missing) > 0)
        {
            throw new InvalidInputException('The password needs ' . self::joinList($missing) . '.');
        }
    }


    /**
     * Describe the rules, for a password field's hint, e.g. "At least 12 characters, with a capital letter and a
     * number."
     *
     * @return string
     */
    public function describe() : string
    {
        $with = [];

        if ($this->m_requiresCapital)
        {
            $with[] = 'a capital letter';
        }

        if ($this->m_requiresNumber)
        {
            $with[] = 'a number';
        }

        if ($this->m_requiresSymbol)
        {
            $with[] = 'a symbol';
        }

        $extra = (count($with) > 0) ? ', with ' . self::joinList($with) : '';
        return "At least {$this->m_minLength} characters{$extra}.";
    }


    /**
     * Join a list in a sentence, e.g. "a, b and c".
     * @param list<string> $items - at least one.
     * @return string
     */
    private static function joinList(array $items) : string
    {
        $last = array_pop($items);
        return (count($items) > 0) ? implode(', ', $items) . " and {$last}" : (string)$last;
    }


    # Accessors
    public function getMinLength() : int { return $this->m_minLength; }
    public function requiresCapital() : bool { return $this->m_requiresCapital; }
    public function requiresNumber() : bool { return $this->m_requiresNumber; }
    public function requiresSymbol() : bool { return $this->m_requiresSymbol; }
}
