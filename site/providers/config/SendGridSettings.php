<?php

/*
 * The settings for a SendGrid provider: its API key, and optionally the public key for its signed event webhook.
 */

declare(strict_types = 1);


readonly class SendGridSettings implements ProviderSettingsInterface
{
    private ConfigValue $m_apiKey;
    private ConfigValue $m_webhookPublicKey;


    /**
     * @param ConfigValue $apiKey - the API key, which needs the "Mail Send" permission (and optionally read access to
     * the rest that SendGridSenderChecker checks). Usually a reference, e.g. "${SENDGRID_API_KEY}".
     *
     * @param ConfigValue $webhookPublicKey - the public key from SendGrid's "Signed Event Webhook" settings, or an
     * empty value to leave the event webhook switched off.
     */
    public function __construct(ConfigValue $apiKey, ConfigValue $webhookPublicKey)
    {
        $this->m_apiKey = $apiKey;
        $this->m_webhookPublicKey = $webhookPublicKey;
    }


    public function getType() : ProviderType
    {
        return ProviderType::SENDGRID;
    }


    public function createProvider(string $name) : SmtpProviderInterface
    {
        $webhookKey = $this->m_webhookPublicKey->isAvailable() ? $this->m_webhookPublicKey->resolve() : '';
        return new SendGridProvider($name, $this->m_apiKey->resolve(), $webhookKey);
    }


    public function fetchSenderChecker(string $name) : SenderCheckerInterface
    {
        $webhookKey = $this->m_webhookPublicKey->isAvailable() ? $this->m_webhookPublicKey->resolve() : '';

        $webhookUrl = ProviderWebhookUrl::get($name);
        $api = SendGridSenderChecker::createApiClient($this->m_apiKey->resolve());
        return SendGridSenderChecker::fetch($name, $api, $webhookKey, $webhookUrl);
    }


    public function getSettings() : ConfigSettingCollection
    {
        return new ConfigSettingCollection(
            new ConfigSetting('api_key', $this->m_apiKey, true),
            new ConfigSetting('webhook_public_key', $this->m_webhookPublicKey, false)
        );
    }
}
