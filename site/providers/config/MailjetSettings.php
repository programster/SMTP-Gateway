<?php

/*
 * The settings for a Mailjet provider: an API key and its secret key (which are its SMTP login, and read its
 * senders for the sender checks), and optionally the secret its event callbacks authenticate with.
 */

declare(strict_types = 1);


readonly class MailjetSettings implements ProviderSettingsInterface
{
    private ConfigValue $m_apiKey;
    private ConfigValue $m_secretKey;
    private ConfigValue $m_webhookSecret;


    /**
     * @param ConfigValue $apiKey - the (public) API key (Account settings → API keys).
     *
     * @param ConfigValue $secretKey - its secret key. Usually a reference, e.g. "${MAILJET_SECRET_KEY}".
     *
     * @param ConfigValue $webhookSecret - the secret its event callbacks authenticate with, or an empty value to
     * leave the webhook switched off.
     */
    public function __construct(ConfigValue $apiKey, ConfigValue $secretKey, ConfigValue $webhookSecret)
    {
        $this->m_apiKey = $apiKey;
        $this->m_secretKey = $secretKey;
        $this->m_webhookSecret = $webhookSecret;
    }


    public function getType() : ProviderType
    {
        return ProviderType::MAILJET;
    }


    public function createProvider(string $name) : SmtpProviderInterface
    {
        $secret = $this->m_webhookSecret->resolveIfAvailable();
        return new MailjetProvider($name, $this->m_apiKey->resolve(), $this->m_secretKey->resolve(), $secret);
    }


    public function fetchSenderChecker(string $name) : SenderCheckerInterface
    {
        $api = MailjetSenderChecker::createApiClient($this->m_apiKey->resolve(), $this->m_secretKey->resolve());
        $secret = $this->m_webhookSecret->resolveIfAvailable();
        return MailjetSenderChecker::fetch($name, $api, $secret, ProviderWebhookUrl::get($name));
    }


    public function getSettings() : ConfigSettingCollection
    {
        return new ConfigSettingCollection(
            new ConfigSetting('api_key', $this->m_apiKey, false),
            new ConfigSetting('secret_key', $this->m_secretKey, true),
            new ConfigSetting('webhook_secret', $this->m_webhookSecret, true)
        );
    }
}
