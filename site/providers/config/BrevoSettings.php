<?php

/*
 * The settings for a Brevo provider: the account's SMTP login and an SMTP key, and optionally an API key (for the
 * sender checks) and the secret its webhook authenticates with.
 */

declare(strict_types = 1);


readonly class BrevoSettings implements ProviderSettingsInterface
{
    private ConfigValue $m_smtpLogin;
    private ConfigValue $m_smtpKey;
    private ConfigValue $m_apiKey;
    private ConfigValue $m_webhookSecret;


    /**
     * @param ConfigValue $smtpLogin - the SMTP login Brevo shows (SMTP & API → SMTP), e.g.
     * "8a1b2c001@smtp-brevo.com".
     *
     * @param ConfigValue $smtpKey - one of the account's SMTP keys. Usually a reference, e.g. "${BREVO_SMTP_KEY}".
     *
     * @param ConfigValue $apiKey - one of the account's API keys, for the sender checks, or an empty value to not
     * check them.
     *
     * @param ConfigValue $webhookSecret - the secret its transactional webhook authenticates with, or an empty value
     * to leave the webhook switched off.
     */
    public function __construct(
        ConfigValue $smtpLogin,
        ConfigValue $smtpKey,
        ConfigValue $apiKey,
        ConfigValue $webhookSecret
    )
    {
        $this->m_smtpLogin = $smtpLogin;
        $this->m_smtpKey = $smtpKey;
        $this->m_apiKey = $apiKey;
        $this->m_webhookSecret = $webhookSecret;
    }


    public function getType() : ProviderType
    {
        return ProviderType::BREVO;
    }


    public function createProvider(string $name) : SmtpProviderInterface
    {
        $secret = $this->m_webhookSecret->resolveIfAvailable();
        return new BrevoProvider($name, $this->m_smtpLogin->resolve(), $this->m_smtpKey->resolve(), $secret);
    }


    public function fetchSenderChecker(string $name) : SenderCheckerInterface
    {
        $apiKey = $this->m_apiKey->resolveIfAvailable();
        $api = ($apiKey === '') ? null : BrevoSenderChecker::createApiClient($apiKey);
        $secret = $this->m_webhookSecret->resolveIfAvailable();
        return BrevoSenderChecker::fetch($name, $api, $secret, ProviderWebhookUrl::get($name));
    }


    public function getSettings() : ConfigSettingCollection
    {
        return new ConfigSettingCollection(
            new ConfigSetting('smtp_login', $this->m_smtpLogin, false),
            new ConfigSetting('smtp_key', $this->m_smtpKey, true),
            new ConfigSetting('api_key', $this->m_apiKey, true),
            new ConfigSetting('webhook_secret', $this->m_webhookSecret, true)
        );
    }
}
