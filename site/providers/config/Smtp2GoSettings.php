<?php

/*
 * The settings for an SMTP2GO provider: one of the account's SMTP users, and optionally an API key for the sender
 * checks.
 */

declare(strict_types = 1);


readonly class Smtp2GoSettings implements ProviderSettingsInterface
{
    private ConfigValue $m_smtpUsername;
    private ConfigValue $m_smtpPassword;
    private ConfigValue $m_apiKey;


    /**
     * @param ConfigValue $smtpUsername - the SMTP user's username (Sending > SMTP Users).
     *
     * @param ConfigValue $smtpPassword - the SMTP user's password.
     *
     * @param ConfigValue $apiKey - an API key allowed to view sender domains and single sender emails, for the sender
     * checks, or an empty value to not check them.
     */
    public function __construct(ConfigValue $smtpUsername, ConfigValue $smtpPassword, ConfigValue $apiKey)
    {
        $this->m_smtpUsername = $smtpUsername;
        $this->m_smtpPassword = $smtpPassword;
        $this->m_apiKey = $apiKey;
    }


    public function getType() : ProviderType
    {
        return ProviderType::SMTP2GO;
    }


    public function createProvider(string $name) : SmtpProviderInterface
    {
        return new Smtp2GoProvider($name, $this->m_smtpUsername->resolve(), $this->m_smtpPassword->resolve());
    }


    public function fetchSenderChecker(string $name) : SenderCheckerInterface
    {
        $apiKey = $this->m_apiKey->resolveIfAvailable();
        $api = ($apiKey === '') ? null : Smtp2GoSenderChecker::createApiClient($apiKey);
        return Smtp2GoSenderChecker::fetch($name, $api);
    }


    public function getSettings() : ConfigSettingCollection
    {
        return new ConfigSettingCollection(
            new ConfigSetting('smtp_username', $this->m_smtpUsername, false),
            new ConfigSetting('smtp_password', $this->m_smtpPassword, true),
            new ConfigSetting('api_key', $this->m_apiKey, true)
        );
    }
}
