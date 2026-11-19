<?php

/*
 * The settings for a Mailgun provider: the region its domain is in, one of the domain's SMTP logins, and optionally
 * an API key (for the sender checks) and the account's webhook signing key (for its events).
 */

declare(strict_types = 1);


readonly class MailgunSettings implements ProviderSettingsInterface
{
    private MailgunRegion $m_region;
    private ConfigValue $m_smtpUsername;
    private ConfigValue $m_smtpPassword;
    private ConfigValue $m_apiKey;
    private ConfigValue $m_webhookSigningKey;


    /**
     * @param MailgunRegion $region - the region the domain is in.
     *
     * @param ConfigValue $smtpUsername - one of the domain's SMTP logins, e.g. "postmaster@mg.example.org". Mailgun
     * DKIM-signs everything sent with it as that domain.
     *
     * @param ConfigValue $smtpPassword - its password. Usually a reference, e.g. "${MAILGUN_SMTP_PASSWORD}".
     *
     * @param ConfigValue $apiKey - an API key that can read the domain and its webhooks, for the sender checks, or
     * an empty value to only check the senders' alignment with the domain.
     *
     * @param ConfigValue $webhookSigningKey - the account's HTTP webhook signing key (Settings → API Security), or
     * an empty value to leave the webhook switched off.
     *
     * @throws InvalidInputException - if the SMTP login, written out in full, isn't an address.
     */
    public function __construct(
        MailgunRegion $region,
        ConfigValue $smtpUsername,
        ConfigValue $smtpPassword,
        ConfigValue $apiKey,
        ConfigValue $webhookSigningKey
    )
    {
        $isLogin = (MailgunProvider::extractDomain($smtpUsername->toConfigString()) !== '');

        if ($smtpUsername->isReference() === false && $isLogin === false)
        {
            throw new InvalidInputException('"smtp_username" must be an SMTP login, e.g. postmaster@mg.example.org.');
        }

        $this->m_region = $region;
        $this->m_smtpUsername = $smtpUsername;
        $this->m_smtpPassword = $smtpPassword;
        $this->m_apiKey = $apiKey;
        $this->m_webhookSigningKey = $webhookSigningKey;
    }


    public function getType() : ProviderType
    {
        return ProviderType::MAILGUN;
    }


    public function createProvider(string $name) : SmtpProviderInterface
    {
        return new MailgunProvider(
            $name,
            $this->m_region,
            $this->m_smtpUsername->resolve(),
            $this->m_smtpPassword->resolve(),
            $this->m_webhookSigningKey->resolveIfAvailable()
        );
    }


    public function fetchSenderChecker(string $name) : SenderCheckerInterface
    {
        $apiKey = $this->m_apiKey->resolveIfAvailable();
        $api = ($apiKey === '') ? null : MailgunSenderChecker::createApiClient($this->m_region, $apiKey);
        $domain = MailgunProvider::extractDomain($this->m_smtpUsername->resolve());
        $isWebhookKeySet = ($this->m_webhookSigningKey->resolveIfAvailable() !== '');
        return MailgunSenderChecker::fetch($name, $domain, $api, $isWebhookKeySet, ProviderWebhookUrl::get($name));
    }


    public function getSettings() : ConfigSettingCollection
    {
        return new ConfigSettingCollection(
            new ConfigSetting('region', $this->m_region->value, false),
            new ConfigSetting('smtp_username', $this->m_smtpUsername, false),
            new ConfigSetting('smtp_password', $this->m_smtpPassword, true),
            new ConfigSetting('api_key', $this->m_apiKey, true),
            new ConfigSetting('webhook_signing_key', $this->m_webhookSigningKey, true)
        );
    }
}
