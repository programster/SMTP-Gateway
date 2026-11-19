<?php

/*
 * The settings for a Postmark provider: a server's API token, the message stream to send into, and optionally an
 * account token (for checking its domains and sender signatures) and the secret its webhook authenticates with.
 */

declare(strict_types = 1);


readonly class PostmarkSettings implements ProviderSettingsInterface
{
    private ConfigValue $m_serverToken;
    private string $m_messageStream;
    private ConfigValue $m_accountToken;
    private ConfigValue $m_webhookSecret;


    /**
     * @param ConfigValue $serverToken - the server's API token, which is also its SMTP username and password.
     * Usually a reference, e.g. "${POSTMARK_SERVER_TOKEN}".
     *
     * @param string $messageStream - the ID of the stream to send into, e.g. "outbound" (the default transactional
     * stream).
     *
     * @param ConfigValue $accountToken - an account API token, for reading the account's domains and sender
     * signatures in the sender checks, or an empty value to not check them.
     *
     * @param ConfigValue $webhookSecret - the password the server's webhook authenticates with, or an empty value
     * to leave the webhook switched off.
     *
     * @throws InvalidInputException - if the stream isn't a stream ID.
     */
    public function __construct(
        ConfigValue $serverToken,
        string $messageStream,
        ConfigValue $accountToken,
        ConfigValue $webhookSecret
    )
    {
        if (preg_match('/^[a-z0-9_-]+$/i', $messageStream) !== 1)
        {
            throw new InvalidInputException('"message_stream" must be a stream\'s ID, e.g. "outbound".');
        }

        $this->m_serverToken = $serverToken;
        $this->m_messageStream = $messageStream;
        $this->m_accountToken = $accountToken;
        $this->m_webhookSecret = $webhookSecret;
    }


    public function getType() : ProviderType
    {
        return ProviderType::POSTMARK;
    }


    public function createProvider(string $name) : SmtpProviderInterface
    {
        $secret = $this->m_webhookSecret->resolveIfAvailable();
        return new PostmarkProvider($name, $this->m_serverToken->resolve(), $this->m_messageStream, $secret);
    }


    public function fetchSenderChecker(string $name) : SenderCheckerInterface
    {
        $serverApi = PostmarkSenderChecker::createServerApiClient($this->m_serverToken->resolve());
        $accountToken = $this->m_accountToken->resolveIfAvailable();
        $accountApi = ($accountToken === '') ? null : PostmarkSenderChecker::createAccountApiClient($accountToken);

        return PostmarkSenderChecker::fetch(
            $name,
            $serverApi,
            $accountApi,
            $this->m_messageStream,
            $this->m_webhookSecret->resolveIfAvailable(),
            ProviderWebhookUrl::get($name)
        );
    }


    public function getSettings() : ConfigSettingCollection
    {
        return new ConfigSettingCollection(
            new ConfigSetting('server_token', $this->m_serverToken, true),
            new ConfigSetting('message_stream', $this->m_messageStream, false),
            new ConfigSetting('account_token', $this->m_accountToken, true),
            new ConfigSetting('webhook_secret', $this->m_webhookSecret, true)
        );
    }
}
