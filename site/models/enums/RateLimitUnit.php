<?php

/*
 * What a rate limit counts.
 */

declare(strict_types = 1);


enum RateLimitUnit : string
{
    // Each email handed to the provider counts as one request, however many recipients it has.
    case EMAILS = 'emails';

    // Each email counts as its number of recipients (To, Cc and Bcc alike), as providers' plans usually do.
    case RECIPIENTS = 'recipients';


    /**
     * Get what's being counted, for describing a limit, e.g. "emails" in "10 emails per second".
     *
     * @param int $count - the number being described, so "1 email" isn't "1 emails".
     *
     * @return string
     */
    public function getNoun(int $count) : string
    {
        return match ($this) {
            RateLimitUnit::EMAILS => ($count === 1) ? 'email' : 'emails',
            RateLimitUnit::RECIPIENTS => ($count === 1) ? 'recipient' : 'recipients',
        };
    }


    /**
     * Get the label for choosing this in a form.
     *
     * @return string
     */
    public function getLabel() : string
    {
        return match ($this) {
            RateLimitUnit::EMAILS => 'emails (requests to the provider)',
            RateLimitUnit::RECIPIENTS => 'recipients',
        };
    }
}
