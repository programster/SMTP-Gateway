<?php

/*
 * A set of rate limits that all apply at once, e.g. 10 emails per second, 300 emails per hour, and 50,000 recipients
 * per 30 days. An email can only be sent when every one of them allows it. It may be empty, which means no limit,
 * and is the default. No two limits can count the same thing over the same period, as only the stricter one could
 * ever have any effect.
 */

declare(strict_types = 1);


readonly class RateLimitSet
{
    private RateLimitCollection $m_limits;


    /**
     * @param RateLimit ...$limits - the limits, in any order. They're kept in order of unit (emails, then
     * recipients), then of period, shortest first.
     *
     * @throws InvalidInputException - if two limits count the same thing over the same period.
     */
    public function __construct(RateLimit ...$limits)
    {
        $byKey = [];

        foreach ($limits as $limit)
        {
            $unitOrder = array_search($limit->getUnit(), RateLimitUnit::cases(), true);
            $key = sprintf('%d-%010d', $unitOrder, $limit->getPeriodSeconds());

            if (isset($byKey[$key]))
            {
                $existing = $byKey[$key];

                $msg =
                    "The rate limits \"{$existing->describe()}\" and \"{$limit->describe()}\" count the same thing"
                    . " over the same period. Remove one of them, as only the stricter one would ever have an effect.";

                throw new InvalidInputException($msg);
            }

            $byKey[$key] = $limit;
        }

        ksort($byKey);
        $this->m_limits = new RateLimitCollection(...array_values($byKey));
    }


    public function isEmpty() : bool
    {
        return count($this->m_limits) === 0;
    }


    /**
     * Get the limits that an email with this many recipients could never be sent within, because they allow fewer
     * recipients than it has, however long it waits.
     *
     * @param int $recipientCount - the email's number of recipients.
     *
     * @return RateLimitCollection - empty if every limit could allow it.
     */
    public function getLimitsThatCanNeverAllow(int $recipientCount) : RateLimitCollection
    {
        $blocking = [];

        foreach ($this->m_limits as $limit)
        {
            /* @var $limit RateLimit */
            if ($limit->canEverAllow($recipientCount) === false)
            {
                $blocking[] = $limit;
            }
        }

        return new RateLimitCollection(...$blocking);
    }


    /**
     * Describe the limits in words, e.g. "10 emails per second, 50,000 recipients per 30 days", or "No limit".
     *
     * @return string
     */
    public function describe() : string
    {
        $descriptions = [];

        foreach ($this->m_limits as $limit)
        {
            /* @var $limit RateLimit */
            $descriptions[] = $limit->describe();
        }

        return (count($descriptions) > 0) ? implode(', ', $descriptions) : 'No limit';
    }


    # Accessors
    public function getLimits() : RateLimitCollection { return clone $this->m_limits; }
}
