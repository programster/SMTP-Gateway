<?php

/*
 * The settings for an SMTP2GO provider: one of the account's SMTP users, an API key, or both, and optionally the
 * secret its webhook authenticates with.
 *
 * With an SMTP login, it sends over SMTP, which can deliver any email. With only an API key, it sends through the API
 * (see Smtp2GoApiProvider), which can't send an email whose To or Cc lists someone who isn't a recipient. The API key
 * is also what the sender checks read with, and what can set up the webhook (see Smtp2GoWebhookSetup).
 */

declare(strict_types = 1);


readonly class Smtp2GoSettings implements ProviderSettingsInterface
{
    private ConfigValue $m_smtpUsername;
    private ConfigValue $m_smtpPassword;
    private ConfigValue $m_apiKey;
    private ConfigValue $m_webhookSecret;


    /**
     * @param ConfigValue $smtpUsername - the SMTP user's username (Sending > SMTP Users), or an empty value.
     *
     * @param ConfigValue $smtpPassword - the SMTP user's password, or an empty value.
     *
     * @param ConfigValue $apiKey - an API key, or an empty value. It needs /email/mime if there's no SMTP login, and
     * /domain/view and /single_sender_emails/view for the sender checks.
     *
     * @param ConfigValue $webhookSecret - the secret its webhook authenticates with, or an empty value to leave the
     * webhook switched off.
     *
     * @throws InvalidInputException - if there's neither an SMTP login nor an API key, or only half of a login.
     */
    public function __construct(
        ConfigValue $smtpUsername,
        ConfigValue $smtpPassword,
        ConfigValue $apiKey,
        ConfigValue $webhookSecret
    )
    {
        $hasUsername = ($smtpUsername->resolveIfAvailable() !== '');
        $hasPassword = ($smtpPassword->resolveIfAvailable() !== '');

        if ($hasUsername !== $hasPassword)
        {
            throw new InvalidInputException('An SMTP login needs both "smtp_username" and "smtp_password".');
        }

        if ($hasUsername === false && $apiKey->resolveIfAvailable() === '')
        {
            $message = 'Set an SMTP login ("smtp_username" and "smtp_password"), an "api_key", or both.';
            throw new InvalidInputException($message);
        }

        $this->m_smtpUsername = $smtpUsername;
        $this->m_smtpPassword = $smtpPassword;
        $this->m_apiKey = $apiKey;
        $this->m_webhookSecret = $webhookSecret;
    }


    public function getType() : ProviderType
    {
        return ProviderType::SMTP2GO;
    }


    public function createProvider(string $name) : SmtpProviderInterface
    {
        $username = $this->m_smtpUsername->resolveIfAvailable();
        $secret = $this->m_webhookSecret->resolveIfAvailable();

        $provider = ($username !== '')
            ? new Smtp2GoProvider($name, $username, $this->m_smtpPassword->resolve(), $secret)
            : Smtp2GoApiProvider::createUsingApiKey($name, $this->m_apiKey->resolve(), $secret);

        return $provider;
    }


    public function fetchSenderChecker(string $name) : SenderCheckerInterface
    {
        $apiKey = $this->m_apiKey->resolveIfAvailable();
        $api = ($apiKey === '') ? null : Smtp2GoSenderChecker::createApiClient($apiKey);
        $isSendingWithApi = ($this->m_smtpUsername->resolveIfAvailable() === '');
        $secret = $this->m_webhookSecret->resolveIfAvailable();
        return Smtp2GoSenderChecker::fetch($name, $api, $isSendingWithApi, $secret, ProviderWebhookUrl::get($name));
    }


    public function getSettings() : ConfigSettingCollection
    {
        return new ConfigSettingCollection(
            new ConfigSetting('smtp_username', $this->m_smtpUsername, false),
            new ConfigSetting('smtp_password', $this->m_smtpPassword, true),
            new ConfigSetting('api_key', $this->m_apiKey, true),
            new ConfigSetting('webhook_secret', $this->m_webhookSecret, true)
        );
    }


    /**
     * Create what sets up the webhook in the SMTP2GO account, with the API key.
     *
     * @return Smtp2GoWebhookInstaller
     *
     * @throws InvalidInputException - if there's no API key.
     */
    public function createWebhookInstaller() : Smtp2GoWebhookInstaller
    {
        $apiKey = $this->m_apiKey->resolveIfAvailable();

        if ($apiKey === '')
        {
            $message = 'Setting up the webhook for you needs an API key. Add one, or do it by hand.';
            throw new InvalidInputException($message);
        }

        return Smtp2GoWebhookInstaller::createUsingApiKey($apiKey);
    }


    public function hasApiKey() : bool
    {
        return $this->m_apiKey->resolveIfAvailable() !== '';
    }


    # Accessors
    public function getWebhookSecret() : string { return $this->m_webhookSecret->resolveIfAvailable(); }
}
