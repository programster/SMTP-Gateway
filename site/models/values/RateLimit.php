<?php

/*
 * A limit on how much can be sent in a sliding window of time: either how many emails (requests to the provider),
 * e.g. 10 per second, or how many recipients, e.g. 50,000 per month (30 days). An email with five recipients counts
 * as one email, but as five recipients.
 */

declare(strict_types = 1);


readonly class RateLimit
{
    public const int MAX_PERIOD_SECONDS = 31 * 86400;

    private RateLimitUnit $m_unit;
    private int $m_max;
    private int $m_periodSeconds;


    /**
     * @param RateLimitUnit $unit - what the limit counts: emails, or recipients.
     *
     * @param int $max - how many (emails or recipients) can be sent within the period. Must be at least 1.
     *
     * @param int $periodSeconds - the length of the window, in seconds. Must be from 1 second to 31 days.
     *
     * @throws InvalidInputException - if either number is out of range.
     */
    public function __construct(RateLimitUnit $unit, int $max, int $periodSeconds)
    {
        if ($max < 1)
        {
            throw new InvalidInputException("A rate limit must allow at least 1 {$unit->getNoun(1)}, not {$max}.");
        }

        if ($periodSeconds < 1 || $periodSeconds > self::MAX_PERIOD_SECONDS)
        {
            $msg = "A rate limit's period must be from 1 to " . self::MAX_PERIOD_SECONDS . " seconds (31 days).";
            throw new InvalidInputException($msg);
        }

        $this->m_unit = $unit;
        $this->m_max = $max;
        $this->m_periodSeconds = $periodSeconds;
    }


    /**
     * Whether an email with this many recipients could ever be sent within this limit. One that couldn't would
     * wait in the queue forever, so it's refused instead.
     *
     * @param int $recipientCount - the email's number of recipients.
     *
     * @return bool
     */
    public function canEverAllow(int $recipientCount) : bool
    {
        return $this->m_unit !== RateLimitUnit::RECIPIENTS || $recipientCount <= $this->m_max;
    }


    /**
     * Describe the limit in words, e.g. "10 emails per second", or "50,000 recipients per 30 days".
     *
     * @return string
     */
    public function describe() : string
    {
        $units = [604800 => 'week', 86400 => 'day', 3600 => 'hour', 60 => 'minute', 1 => 'second'];
        $period = "{$this->m_periodSeconds} seconds";
        $found = false;

        foreach ($units as $unitSeconds => $unitName)
        {
            if ($found === false && $this->m_periodSeconds % $unitSeconds === 0)
            {
                $count = intdiv($this->m_periodSeconds, $unitSeconds);
                $period = ($count === 1) ? $unitName : "{$count} {$unitName}s";
                $found = true;
            }
        }

        return number_format($this->m_max) . " {$this->m_unit->getNoun($this->m_max)} per {$period}";
    }


    # Accessors
    public function getUnit() : RateLimitUnit { return $this->m_unit; }
    public function getMax() : int { return $this->m_max; }
    public function getPeriodSeconds() : int { return $this->m_periodSeconds; }
}
