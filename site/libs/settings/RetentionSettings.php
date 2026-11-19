<?php

/*
 * The retention section of settings.yaml: how long each part of an email is kept (EmailRetentionPolicy), and how long
 * the logs are kept.
 */

declare(strict_types = 1);


readonly class RetentionSettings
{
    private EmailRetentionPolicy $m_emailRetention;
    private int $m_logDays;


    /**
     * @param EmailRetentionPolicy $emailRetention - how long each part of an email is kept.
     *
     * @param int $logDays - how many days the logs are kept.
     *
     * @throws InvalidInputException - if $logDays isn't above zero.
     */
    public function __construct(EmailRetentionPolicy $emailRetention, int $logDays)
    {
        if ($logDays < 1)
        {
            throw new InvalidInputException("The logs must be kept for at least 1 day, not {$logDays}.");
        }

        $this->m_emailRetention = $emailRetention;
        $this->m_logDays = $logDays;
    }


    /**
     * Create the settings for when there's no retention section: email records and logs kept for 90 days, and
     * emails' content (and so their attachments) not kept once they've been sent.
     *
     * @return RetentionSettings
     */
    public static function createDefault() : RetentionSettings
    {
        return new RetentionSettings(EmailRetentionPolicy::createFromSettings('90', '-1', '-1'), 90);
    }


    # Accessors
    public function getEmailRetention() : EmailRetentionPolicy { return $this->m_emailRetention; }
    public function getLogDays() : int { return $this->m_logDays; }
}
