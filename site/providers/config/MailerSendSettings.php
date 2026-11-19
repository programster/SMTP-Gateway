<?php

/*
 * The settings for a MailerSend provider: one of its SMTP users, and optionally an API token (for the sender checks)
 * and its webhook's signing secret.
 */

declare(strict_types = 1);


readonly class MailerSendSettings implements ProviderSettingsInterface
{
    private ConfigValue $m_smtpUsername;
    private ConfigValue $m_smtpPassword;
    private ConfigValue $m_apiToken;
    private ConfigValue $m_webhookSecret;


    /**
     * @param ConfigValue $smtpUsername - one of the account's SMTP users' usernames (Domains → the domain → SMTP).
     *
     * @param ConfigValue $smtpPassword - its password. Usually a reference, e.g. "${MAILERSEND_SMTP_PASSWORD}".
     *
     * @param ConfigValue $apiToken - an API token with read access to domains (and full access to webhooks, to check
     * them), for the sender checks, or an empty value to not check them.
     *
     * @param ConfigValue $webhookSecret - the signing secret of the webhook that posts here, or an empty value to
     * leave the webhook switched off.
     */
    public function __construct(
        ConfigValue $smtpUsername,
        ConfigValue $smtpPassword,
        ConfigValue $apiToken,
        ConfigValue $webhookSecret
    )
    {
        $this->m_smtpUsername = $smtpUsername;
        $this->m_smtpPassword = $smtpPassword;
        $this->m_apiToken = $apiToken;
        $this->m_webhookSecret = $webhookSecret;
    }


    public function getType() : ProviderType
    {
        return ProviderType::MAILERSEND;
    }


    public function createProvider(string $name) : SmtpProviderInterface
    {
        $username = $this->m_smtpUsername->resolve();
        $secret = $this->m_webhookSecret->resolveIfAvailable();
        return new MailerSendProvider($name, $username, $this->m_smtpPassword->resolve(), $secret);
    }


    public function fetchSenderChecker(string $name) : SenderCheckerInterface
    {
        $token = $this->m_apiToken->resolveIfAvailable();
        $api = ($token === '') ? null : MailerSendSenderChecker::createApiClient($token);
        $secret = $this->m_webhookSecret->resolveIfAvailable();
        return MailerSendSenderChecker::fetch($name, $api, $secret, ProviderWebhookUrl::get($name));
    }


    public function getSettings() : ConfigSettingCollection
    {
        return new ConfigSettingCollection(
            new ConfigSetting('smtp_username', $this->m_smtpUsername, false),
            new ConfigSetting('smtp_password', $this->m_smtpPassword, true),
            new ConfigSetting('api_token', $this->m_apiToken, true),
            new ConfigSetting('webhook_secret', $this->m_webhookSecret, true)
        );
    }
}
