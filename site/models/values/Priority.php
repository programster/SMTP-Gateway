<?php

/*
 * How important an email is in the queue: emails with a higher priority are sent before those with a lower one, and
 * emails with the same priority in the order they arrived. Rate limits still apply whatever the priority; it only
 * decides which email goes first when there's room.
 *
 * An email token's priority is from 1 (the default) to MAX_EMAIL_TOKEN_PRIORITY (forEmailToken()). Only the
 * service's own emails (e.g. password reset links) have the priority above that (system()), so nothing a token sends
 * can go ahead of them.
 */

declare(strict_types = 1);


readonly class Priority
{
    public const int DEFAULT = 1;
    public const int MAX_EMAIL_TOKEN_PRIORITY = 9998;
    private const int SYSTEM = 9999;

    private int $m_value;


    private function __construct(int $value)
    {
        $this->m_value = $value;
    }


    /**
     * Create an email token's priority.
     *
     * @param int $value - from 1 to MAX_EMAIL_TOKEN_PRIORITY.
     *
     * @return Priority
     *
     * @throws InvalidInputException - if it's outside that range.
     */
    public static function forEmailToken(int $value) : Priority
    {
        if ($value < 1 || $value > self::MAX_EMAIL_TOKEN_PRIORITY)
        {
            throw new InvalidInputException(self::describeRange() . ", not {$value}.");
        }

        return new Priority($value);
    }


    /**
     * Read an email token's priority as typed (in a form).
     *
     * @param string $value - e.g. "5". Empty means the default.
     *
     * @return Priority
     *
     * @throws InvalidInputException - if it isn't a whole number from 1 to MAX_EMAIL_TOKEN_PRIORITY.
     */
    public static function forKeyFromString(string $value) : Priority
    {
        $value = trim($value);

        if ($value === '')
        {
            $priority = self::forEmailToken(self::DEFAULT);
        }
        elseif (preg_match('/^[0-9]{1,5}$/', $value) === 1)
        {
            $priority = self::forEmailToken((int)$value);
        }
        else
        {
            throw new InvalidInputException(self::describeRange() . ", not \"{$value}\".");
        }

        return $priority;
    }


    /**
     * Get the priority of the service's own emails, which go ahead of every token's.
     *
     * @return Priority
     */
    public static function system() : Priority
    {
        return new Priority(self::SYSTEM);
    }


    private static function describeRange() : string
    {
        $max = self::MAX_EMAIL_TOKEN_PRIORITY;
        return "The priority must be a whole number from 1 (the lowest, and the default) to {$max}";
    }


    # Accessors
    public function getValue() : int { return $this->m_value; }
}
