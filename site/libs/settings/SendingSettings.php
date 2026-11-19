<?php

/*
 * The sending section of settings.yaml: the address the service's own emails (password reset links) are sent from,
 * and how long the SMTP server and the send endpoint wait to tell a client an email was sent.
 */

declare(strict_types = 1);


readonly class SendingSettings
{
    public const int MAX_SYNC_WAIT_SECONDS = 60;
    private const int DEFAULT_SYNC_WAIT_SECONDS = 5;

    private string $m_systemEmailFrom;
    private int $m_syncWaitSeconds;


    /**
     * @param string $systemEmailFrom - the address the service's own emails are sent from, e.g. "noreply@example.org",
     * or an empty string for none, in which case forgotten passwords can't be reset by email.
     *
     * @param int $syncWaitSeconds - how long (0 to 60 seconds) a client waits to be told an email was sent, when it
     * looks like a worker will start sending it within that time. 0 never waits, and always replies "queued".
     *
     * @throws InvalidInputException - if the address isn't valid, or the wait is out of range.
     */
    public function __construct(string $systemEmailFrom, int $syncWaitSeconds)
    {
        $systemEmailFrom = trim($systemEmailFrom);

        if ($syncWaitSeconds < 0 || $syncWaitSeconds > self::MAX_SYNC_WAIT_SECONDS)
        {
            $max = self::MAX_SYNC_WAIT_SECONDS;
            $msg = "How long a client waits must be from 0 to {$max} seconds, not {$syncWaitSeconds}.";
            throw new InvalidInputException($msg);
        }

        $this->m_systemEmailFrom = ($systemEmailFrom === '') ? '' : (string)new EmailAddress($systemEmailFrom);
        $this->m_syncWaitSeconds = $syncWaitSeconds;
    }


    /**
     * Create the settings for when there's no sending section, or it's invalid: no address for the service's own
     * emails, and a 5 second wait.
     *
     * @return SendingSettings
     */
    public static function createDefault() : SendingSettings
    {
        return new SendingSettings('', self::DEFAULT_SYNC_WAIT_SECONDS);
    }


    public function hasSystemEmailFrom() : bool
    {
        return $this->m_systemEmailFrom !== '';
    }


    /**
     * Get the address the service's own emails are sent from.
     * @return EmailAddress
     * @throws ConfigurationException - if there isn't one.
     */
    public function getSystemEmailFrom() : EmailAddress
    {
        if (!$this->hasSystemEmailFrom())
        {
            throw new ConfigurationException('No address has been set for the service\'s own emails to be sent from.');
        }

        return new EmailAddress($this->m_systemEmailFrom);
    }


    # Accessors
    public function getSyncWaitSeconds() : int { return $this->m_syncWaitSeconds; }
}
