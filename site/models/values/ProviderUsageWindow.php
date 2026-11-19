<?php

/*
 * How much was sent through a provider within a recent window (e.g. the last hour), counted as its rate limits here
 * count it, for the warning that its own rate limit was reached (see ProviderRateLimitWarning).
 */

declare(strict_types = 1);


final readonly class ProviderUsageWindow
{
    private string $m_label;
    private int $m_periodSeconds;
    private int $m_emails;
    private int $m_recipients;


    /**
     * @param string $label - the window, as written in a sentence, e.g. "hour".
     *
     * @param int $periodSeconds - how long it is.
     *
     * @param int $emails - how many emails were sent through the provider within it.
     *
     * @param int $recipients - how many recipients they had in all.
     */
    public function __construct(string $label, int $periodSeconds, int $emails, int $recipients)
    {
        $this->m_label = $label;
        $this->m_periodSeconds = $periodSeconds;
        $this->m_emails = $emails;
        $this->m_recipients = $recipients;
    }


    # Accessors
    public function getLabel() : string { return $this->m_label; }
    public function getPeriodSeconds() : int { return $this->m_periodSeconds; }
    public function getEmails() : int { return $this->m_emails; }
    public function getRecipients() : int { return $this->m_recipients; }
}
