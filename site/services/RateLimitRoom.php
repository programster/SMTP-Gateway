<?php

/*
 * How much more a set of rate limits would let through right now: how many more emails, and how many more
 * recipients. PHP_INT_MAX means there's no limit on it.
 */

declare(strict_types = 1);


readonly class RateLimitRoom
{
    private int $m_emails;
    private int $m_recipients;


    public function __construct(int $emails, int $recipients)
    {
        $this->m_emails = max(0, $emails);
        $this->m_recipients = max(0, $recipients);
    }


    # Accessors
    public function getEmails() : int { return $this->m_emails; }
    public function getRecipients() : int { return $this->m_recipients; }
}
