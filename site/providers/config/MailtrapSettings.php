<?php

/*
 * The settings for a Mailtrap provider: one of a sending domain's API tokens (its SMTP password), and optionally an
 * API token for the sender checks, and its webhook's signing secret.
 */

declare(strict_types = 1);


readonly class MailtrapSettings implements ProviderSettingsInterface
{
    private ConfigValue $m_smtpToken;
    private ConfigValue $m_apiToken;
    private ConfigValue $m_webhookSecret;


    /**
     * @param ConfigValue $smtpToken - one of the sending domain's API tokens (Sending Domains → the domain →
     * Integration → SMTP). Usually a reference, e.g. "${MAILTRAP_SMTP_TOKEN}".
     *
     * @param ConfigValue $apiToken - an API token with at least viewer access to the account, for the sender checks,
     * or an empty value to not check them.
     *
     * @param ConfigValue $webhookSecret - the signing secret of the webhook that posts here, or an empty value to
     * leave the webhook switched off.
     */
    public function __construct(ConfigValue $smtpToken, ConfigValue $apiToken, ConfigValue $webhookSecret)
    {
        $this->m_smtpToken = $smtpToken;
        $this->m_apiToken = $apiToken;
        $this->m_webhookSecret = $webhookSecret;
    }


    public function getType() : ProviderType
    {
        return ProviderType::MAILTRAP;
    }


    public function createProvider(string $name) : SmtpProviderInterface
    {
        $secret = $this->m_webhookSecret->resolveIfAvailable();
        return new MailtrapProvider($name, $this->m_smtpToken->resolve(), $secret);
    }


    public function fetchSenderChecker(string $name) : SenderCheckerInterface
    {
        $token = $this->m_apiToken->resolveIfAvailable();
        $api = ($token === '') ? null : MailtrapSenderChecker::createApiClient($token);
        $secret = $this->m_webhookSecret->resolveIfAvailable();
        return MailtrapSenderChecker::fetch($name, $api, $secret, ProviderWebhookUrl::get($name));
    }


    public function getSettings() : ConfigSettingCollection
    {
        return new ConfigSettingCollection(
            new ConfigSetting('smtp_token', $this->m_smtpToken, true),
            new ConfigSetting('api_token', $this->m_apiToken, true),
            new ConfigSetting('webhook_secret', $this->m_webhookSecret, true)
        );
    }
}
