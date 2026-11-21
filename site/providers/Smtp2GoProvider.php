<?php

/*
 * Sends email through SMTP2GO's SMTP relay, mail.smtp2go.com, logging in as one of the account's SMTP users.
 *
 * Every X-Smtp2go-* header the client sent is removed, so a client can't affect how SMTP2GO handles the email. What
 * happens to an email after that is reported by its webhook (see Smtp2GoWebhook).
 */

declare(strict_types = 1);

use Psr\Http\Message\ServerRequestInterface;


final class Smtp2GoProvider extends SmtpRelayProvider implements ProviderWebhookInterface
{
    private readonly Smtp2GoWebhook $m_webhook;


    /**
     * @param string $name - the provider's name in settings.yaml. Its webhook is at /webhooks/<name>.
     *
     * @param string $username - the SMTP user's username.
     *
     * @param string $password - the SMTP user's password.
     *
     * @param string $webhookSecret - the secret its webhook authenticates with, or an empty string to leave the webhook
     * switched off.
     *
     * @throws ConfigurationException - if the username or password is empty, or the secret is too short.
     */
    public function __construct(string $name, string $username, string $password, string $webhookSecret)
    {
        if (trim($username) === '' || trim($password) === '')
        {
            $message = "The SMTP2GO provider \"{$name}\" needs an smtp_username and smtp_password.";
            throw new ConfigurationException($message);
        }

        $host = 'mail.smtp2go.com';
        $relaySettings = new SmtpRelaySettings($host, 587, trim($username), trim($password), SmtpSecurity::TLS);
        parent::__construct($name, $relaySettings);
        $this->m_webhook = new Smtp2GoWebhook($name, $webhookSecret);
    }


    public function getType() : ProviderType
    {
        return ProviderType::SMTP2GO;
    }


    public function isWebhookEnabled() : bool
    {
        return $this->m_webhook->isEnabled();
    }


    public function parseWebhook(string $body, ServerRequestInterface $request) : ProviderEventCollection
    {
        return $this->m_webhook->parse($body, $request);
    }


    /**
     * Remove any X-Smtp2go-* header the client sent.
     *
     * @param OutgoingEmail $email - the email.
     *
     * @return RawEmail
     */
    protected function prepareForSending(OutgoingEmail $email) : RawEmail
    {
        return $email->getRawEmail()->withoutHeadersStartingWith('X-Smtp2go-');
    }
}
