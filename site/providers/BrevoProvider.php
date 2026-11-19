<?php

/*
 * Sends email through Brevo's (formerly Sendinblue's) SMTP relay, smtp-relay.brevo.com, logging in with the
 * account's SMTP login and an SMTP key (not an API key).
 *
 * Every X-Mailin-* and X-Sib-* header the client sent is removed (they're Brevo's own controls, e.g. X-Sib-Sandbox
 * stops it being sent at all), and replaced with an X-Mailin-custom header carrying our ID for the email, which
 * Brevo passes back in its webhooks. Brevo leaves that header in the delivered email.
 *
 * Brevo doesn't sign its webhooks, so they're checked with the webhook_secret instead (see WebhookBasicAuth),
 * given as the password in the webhook's address, or as its bearer token.
 */

declare(strict_types = 1);

use Psr\Http\Message\ServerRequestInterface;


final class BrevoProvider extends SmtpRelayProvider implements ProviderWebhookInterface
{
    private readonly string $m_webhookSecret;


    /**
     * @param string $name - the provider's name in settings.yaml. Its webhook is at /webhooks/<name>.
     *
     * @param string $smtpLogin - the SMTP login Brevo shows (SMTP & API → SMTP), e.g. "8a1b2c001@smtp-brevo.com".
     *
     * @param string $smtpKey - one of the account's SMTP keys.
     *
     * @param string $webhookSecret - the secret Brevo's webhook authenticates with, or an empty string to leave the
     * webhook switched off.
     *
     * @throws ConfigurationException - if the login or key is empty, or the secret is too short.
     */
    public function __construct(string $name, string $smtpLogin, string $smtpKey, string $webhookSecret)
    {
        if (trim($smtpLogin) === '' || trim($smtpKey) === '')
        {
            throw new ConfigurationException("The Brevo provider \"{$name}\" needs an smtp_login and smtp_key.");
        }

        WebhookBasicAuth::assertValidSecret($name, $webhookSecret);
        $host = 'smtp-relay.brevo.com';
        $relaySettings = new SmtpRelaySettings($host, 587, trim($smtpLogin), $smtpKey, SmtpSecurity::TLS);
        parent::__construct($name, $relaySettings);
        $this->m_webhookSecret = $webhookSecret;
    }


    public function getType() : ProviderType
    {
        return ProviderType::BREVO;
    }


    public function isWebhookEnabled() : bool
    {
        return $this->m_webhookSecret !== '';
    }


    /**
     * Check a webhook request has the secret, and get its events.
     *
     * @param string $body - the raw request body: one event as a JSON object, or a batch of them as an array.
     *
     * @param ServerRequestInterface $request - the request, for its Authorization header.
     *
     * @return ProviderEventCollection
     *
     * @throws RequestRejectedException - if it doesn't have the secret, or the webhook isn't enabled.
     *
     * @throws InvalidInputException - if the body isn't the JSON Brevo sends.
     */
    public function parseWebhook(string $body, ServerRequestInterface $request) : ProviderEventCollection
    {
        if (WebhookBasicAuth::isAuthorised($request, $this->m_webhookSecret) === false)
        {
            throw new RequestRejectedException(RejectionReason::INVALID_CREDENTIALS, 'Invalid webhook credentials.');
        }

        $data = json_decode($body, true);

        if (!is_array($data))
        {
            throw new InvalidInputException('Expected a JSON event, or an array of them.');
        }

        $collection = [];

        foreach (array_is_list($data) ? $data : [$data] as $event)
        {
            if (is_array($event) && is_string($event['event'] ?? null))
            {
                $collection[] = self::convertEvent($event);
            }
        }

        return new ProviderEventCollection(...$collection);
    }


    /**
     * Swap any of Brevo's headers the client sent for one carrying our ID.
     *
     * @param OutgoingEmail $email - the email.
     *
     * @return RawEmail
     */
    protected function prepareForSending(OutgoingEmail $email) : RawEmail
    {
        return $email->getRawEmail()
            ->withoutHeadersStartingWith('X-Mailin-')
            ->withoutHeadersStartingWith('X-Sib-')
            ->withHeaderPrepended('X-Mailin-custom', $email->getMessageId());
    }


    /**
     * Convert one of Brevo's transactional webhook events into a ProviderEvent. A soft bounce is temporary (Brevo
     * tries again), a blocked or invalid address isn't tried at all, and "error" is Brevo failing to send it.
     *
     * @param array $event - the event, decoded from JSON.
     *
     * @return ProviderEvent
     */
    private static function convertEvent(array $event) : ProviderEvent
    {
        $name = (string)$event['event'];

        $type = match ($name) {
            'request' => EmailEventType::PROCESSED,
            'delivered' => EmailEventType::DELIVERED,
            'deferred', 'soft_bounce' => EmailEventType::DEFERRED,
            'hard_bounce', 'invalid_email' => EmailEventType::BOUNCED,
            'blocked', 'error' => EmailEventType::DROPPED,
            'spam' => EmailEventType::SPAM_REPORT,
            'opened', 'unique_opened', 'proxy_open', 'unique_proxy_open' => EmailEventType::OPENED,
            'click' => EmailEventType::CLICKED,
            'unsubscribed' => EmailEventType::UNSUBSCRIBED,
            default => EmailEventType::OTHER,
        };

        $detailParts = ($type === EmailEventType::OTHER) ? [$name] : [];

        foreach (['reason', 'link'] as $field)
        {
            if (isset($event[$field]) && is_scalar($event[$field]) && trim((string)$event[$field]) !== '')
            {
                $detailParts[] = trim((string)$event[$field]);
            }
        }

        $recipient = (string)($event['email'] ?? '');
        $hasTimestamp = (isset($event['ts_event']) && is_numeric($event['ts_event']));
        $timestamp = $hasTimestamp ? (int)$event['ts_event'] : time();
        $brevoId = (string)($event['message-id'] ?? '');

        return new ProviderEvent(
            messageId: (string)($event['X-Mailin-custom'] ?? ''),
            // Brevo's events have no ID of their own, so they're told apart by what happened to whom, and when.
            providerEventId: "{$name}:{$brevoId}:{$recipient}:{$timestamp}",
            type: $type,
            recipient: $recipient,
            occurredAt: (new DateTimeImmutable())->setTimestamp($timestamp),
            detail: implode(' - ', $detailParts),
        );
    }
}
