<?php

/*
 * Every section of settings.yaml apart from the providers (see SettingsFile::loadProviders()): the service's settings
 * that are changed on the Settings page. Get them with Settings::get().
 *
 * A section that's missing, or has settings missing, has the defaults. One that's invalid (only possible by editing the
 * file by hand, as every save is checked) has its defaults too, each the safe choice, and the problem is listed in
 * getProblems(), for the Settings page to show. The exception is the retention section, whose default (content not
 * kept) would delete content that was meant to be kept: while it's invalid, getRetention() throws, so nothing is
 * deleted until it's fixed.
 */

declare(strict_types = 1);


readonly class ServiceSettings
{
    private GeneralSettings $m_general;
    private SecuritySettings $m_security;
    private ProxySettings $m_proxies;
    private SendingSettings $m_sending;
    private RetentionSettings $m_retention;
    private bool $m_isRetentionValid;
    private GoogleSsoConfig $m_googleSso;

    /** @var list<string> */
    private array $m_problems;


    /**
     * @param GeneralSettings $general
     *
     * @param SecuritySettings $security
     *
     * @param ProxySettings $proxies
     *
     * @param SendingSettings $sending
     *
     * @param RetentionSettings $retention - the retention settings, or the defaults if they're invalid.
     *
     * @param bool $isRetentionValid - whether $retention was read from the file (or it has no retention section),
     * rather than being the defaults in place of an invalid section.
     *
     * @param GoogleSsoConfig $googleSso
     *
     * @param string ...$problems - why each invalid section couldn't be used, to show on the Settings page.
     */
    public function __construct(
        GeneralSettings $general,
        SecuritySettings $security,
        ProxySettings $proxies,
        SendingSettings $sending,
        RetentionSettings $retention,
        bool $isRetentionValid,
        GoogleSsoConfig $googleSso,
        string ...$problems
    )
    {
        $this->m_general = $general;
        $this->m_security = $security;
        $this->m_proxies = $proxies;
        $this->m_sending = $sending;
        $this->m_retention = $retention;
        $this->m_isRetentionValid = $isRetentionValid;
        $this->m_googleSso = $googleSso;
        $this->m_problems = array_values($problems);
    }


    /**
     * Get how long emails and the logs are kept.
     *
     * @return RetentionSettings
     *
     * @throws ConfigurationException - if the retention section is invalid, so that nothing is deleted until it's
     * fixed.
     */
    public function getRetention() : RetentionSettings
    {
        if (!$this->m_isRetentionValid)
        {
            $msg =
                'The retention settings in settings.yaml are invalid, so nothing is being deleted until they\'re'
                . ' fixed on the Settings page.';

            throw new ConfigurationException($msg);
        }

        return $this->m_retention;
    }


    /**
     * Describe how long emails' content is kept once they've been sent, e.g. "not kept", for the web interface.
     * @return string
     */
    public function describeContentRetention() : string
    {
        return $this->m_isRetentionValid
            ? $this->m_retention->getEmailRetention()->describeContent()
            : 'kept for now, as the retention settings are invalid';
    }


    /**
     * Describe how long emails' attachments are kept once they've been sent, e.g. "not kept", for the web interface.
     * @return string
     */
    public function describeAttachmentRetention() : string
    {
        return $this->m_isRetentionValid
            ? $this->m_retention->getEmailRetention()->describeAttachments()
            : 'kept for now, as the retention settings are invalid';
    }


    /**
     * Whether forgotten passwords can be reset by email: when there's an address to send the email from, and a
     * PUBLIC_URL for its link.
     *
     * @return bool
     */
    public function canSendPasswordResets() : bool
    {
        return Config::get()->hasPublicUrl() && $this->m_sending->hasSystemEmailFrom();
    }


    /**
     * Get the settings for logging in with Google.
     *
     * @return GoogleSsoSettings
     *
     * @throws ConfigurationException - if it isn't switched on, or can't be used (see
     * GoogleSsoConfig::createSettings()).
     */
    public function createGoogleSsoSettings() : GoogleSsoSettings
    {
        $publicUrl = Config::get()->hasPublicUrl() ? Config::get()->getPublicUrl() : '';
        return $this->m_googleSso->createSettings($publicUrl);
    }


    public function getTimezone() : DateTimeZone { return $this->m_general->getTimezone(); }
    public function getDateTimeFormat() : DateTimeFormat { return $this->m_general->getDateTimeFormat(); }
    public function isMfaRequired() : bool { return $this->m_security->isMfaRequired(); }
    public function getTrustedProxies() : IpNetworkCollection { return $this->m_proxies->getTrustedProxies(); }
    public function getSyncWaitSeconds() : int { return $this->m_sending->getSyncWaitSeconds(); }
    public function isGoogleSsoEnabled() : bool { return $this->m_googleSso->isEnabled(); }


    # Accessors
    public function getGeneral() : GeneralSettings { return $this->m_general; }
    public function getSecurity() : SecuritySettings { return $this->m_security; }
    public function getProxies() : ProxySettings { return $this->m_proxies; }
    public function getSending() : SendingSettings { return $this->m_sending; }
    public function getGoogleSso() : GoogleSsoConfig { return $this->m_googleSso; }
    /** @return list<string> */
    public function getProblems() : array { return $this->m_problems; }
}
