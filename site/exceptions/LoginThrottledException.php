<?php

/*
 * Thrown when a login attempt is refused without being checked, as its IP address has failed too many times recently
 * (see LoginThrottle). Its message says how long until it can try again.
 */

declare(strict_types = 1);


class LoginThrottledException extends Exception
{
    private readonly int $m_minutesLeft;


    /**
     * @param int $minutesLeft - how many minutes until the address can try again, at least 1.
     */
    public function __construct(int $minutesLeft)
    {
        $minutes = ($minutesLeft === 1) ? '1 minute' : "{$minutesLeft} minutes";
        parent::__construct("Too many failed attempts to log in from your address. Try again in {$minutes}.");
        $this->m_minutesLeft = $minutesLeft;
    }


    # Accessors
    public function getMinutesLeft() : int { return $this->m_minutesLeft; }
}
