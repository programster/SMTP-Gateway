<?php

/*
 * The settings for a "custom" provider: an SMTP server of your own that email is relayed through (e.g. mailcow, a
 * company mail server, or Mailpit), with the DKIM selector it signs with, if it's known, for the sender checks.
 */

declare(strict_types = 1);


readonly class SmtpSettings implements ProviderSettingsInterface
{
    private ConfigValue $m_host;
    private int $m_port;
    private SmtpSecurity $m_security;
    private ConfigValue $m_username;
    private ConfigValue $m_password;
    private string $m_dkimSelector;


    /**
     * @param ConfigValue $host - the server's hostname.
     *
     * @param int $port - its port, from 1 to 65535.
     *
     * @param SmtpSecurity $security - how to secure the connection.
     *
     * @param ConfigValue $username - the username to log in with, or an empty value to not log in.
     *
     * @param ConfigValue $password - the password. Usually a reference, e.g. "${MAIL_SERVER_PASSWORD}".
     *
     * @param string $dkimSelector - the selector the server DKIM-signs email with (e.g. mailcow's "dkim"), so the
     * sender checks can look for each sender's DKIM key, or an empty string if it isn't known.
     *
     * @throws InvalidInputException - if the port is out of range, the host is empty, or the DKIM selector isn't a
     * valid DNS label.
     */
    public function __construct(
        ConfigValue $host,
        int $port,
        SmtpSecurity $security,
        ConfigValue $username,
        ConfigValue $password,
        string $dkimSelector
    )
    {
        if ($port < 1 || $port > 65535)
        {
            throw new InvalidInputException("The port must be from 1 to 65535, not {$port}.");
        }

        if ($host->isReference() === false && trim($host->toConfigString()) === '')
        {
            throw new InvalidInputException('The host must be set.');
        }

        $label = '[a-z0-9]([a-z0-9_-]*[a-z0-9])?';

        if ($dkimSelector !== '' && preg_match("/^{$label}(\\.{$label})*\$/i", $dkimSelector) !== 1)
        {
            $msg = "\"dkim_selector\" must be a DNS label, e.g. \"dkim\", not \"{$dkimSelector}\".";
            throw new InvalidInputException($msg);
        }

        $this->m_host = $host;
        $this->m_port = $port;
        $this->m_security = $security;
        $this->m_username = $username;
        $this->m_password = $password;
        $this->m_dkimSelector = $dkimSelector;
    }


    public function getType() : ProviderType
    {
        return ProviderType::CUSTOM;
    }


    public function createProvider(string $name) : SmtpProviderInterface
    {
        $username = $this->m_username->isAvailable() ? $this->m_username->resolve() : '';
        $password = ($username === '') ? '' : $this->m_password->resolve();

        $settings = new SmtpRelaySettings(
            $this->m_host->resolve(),
            $this->m_port,
            $username,
            $password,
            $this->m_security
        );

        return new SmtpRelayProvider($name, $settings);
    }


    public function fetchSenderChecker(string $name) : SenderCheckerInterface
    {
        $host = $this->m_host->resolve();
        return CustomSenderChecker::fetch($name, new SystemDnsResolver(), $host, $this->m_dkimSelector);
    }


    public function getSettings() : ConfigSettingCollection
    {
        return new ConfigSettingCollection(
            new ConfigSetting('host', $this->m_host, false),
            new ConfigSetting('port', $this->m_port, false),
            new ConfigSetting('security', $this->m_security->value, false),
            new ConfigSetting('username', $this->m_username, false),
            new ConfigSetting('password', $this->m_password, true),
            new ConfigSetting('dkim_selector', $this->m_dkimSelector, false)
        );
    }
}
