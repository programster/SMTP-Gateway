<?php

/*
 * Sends email through Mailjet's SMTP relay (in-v3.mailjet.com), logging in with an API key and its secret key.
 *
 * Every X-MJ-* and X-Mailjet-* header the client sent is removed (they're Mailjet's own controls: e.g.
 * X-MJ-TemplateErrorReporting sends a copy to another address, X-MJ-TemplateID replaces the body, and
 * X-Mailjet-TrackOpen overrides the account's tracking), and replaced with an X-MJ-CustomID header carrying our ID
 * for the email, which Mailjet passes back in its events (as "CustomID").
 *
 * Mailjet doesn't sign its event callbacks, so they're checked with the webhook_secret instead (see
 * WebhookBasicAuth), given as the password in the callback's address.
 */

declare(strict_types = 1);

use Psr\Http\Message\ServerRequestInterface;


final class MailjetProvider extends SmtpRelayProvider implements ProviderWebhookInterface
{
    private readonly string $m_webhookSecret;


    /**
     * @param string $name - the provider's name in settings.yaml. Its webhook is at /webhooks/<name>.
     *
     * @param string $apiKey - the (public) API key, which is also the SMTP username.
     *
     * @param string $secretKey - its secret key, which is also the SMTP password.
     *
     * @param string $webhookSecret - the secret Mailjet's event callbacks authenticate with, or an empty string to
     * leave the webhook switched off.
     *
     * @throws ConfigurationException - if a key is empty, or the secret is too short.
     */
    public function __construct(string $name, string $apiKey, string $secretKey, string $webhookSecret)
    {
        if (trim($apiKey) === '' || trim($secretKey) === '')
        {
            throw new ConfigurationException("The Mailjet provider \"{$name}\" needs an api_key and secret_key.");
        }

        WebhookBasicAuth::assertValidSecret($name, $webhookSecret);
        $host = 'in-v3.mailjet.com';
        $relaySettings = new SmtpRelaySettings($host, 587, trim($apiKey), $secretKey, SmtpSecurity::TLS);
        parent::__construct($name, $relaySettings);
        $this->m_webhookSecret = $webhookSecret;
    }


    public function getType() : ProviderType
    {
        return ProviderType::MAILJET;
    }


    public function isWebhookEnabled() : bool
    {
        return $this->m_webhookSecret !== '';
    }


    /**
     * Check an event callback request has the secret, and get its events.
     *
     * @param string $body - the raw request body: one event as a JSON object, or several grouped as an array.
     *
     * @param ServerRequestInterface $request - the request, for its Authorization header.
     *
     * @return ProviderEventCollection
     *
     * @throws RequestRejectedException - if it doesn't have the secret, or the webhook isn't enabled.
     *
     * @throws InvalidInputException - if the body isn't the JSON Mailjet sends.
     */
    public function parseWebhook(string $body, ServerRequestInterface $request) : ProviderEventCollection
    {
        if (WebhookBasicAuth::isAuthorised($request, $this->m_webhookSecret) === false)
        {
            throw new RequestRejectedException(RejectionReason::INVALID_CREDENTIALS, 'Invalid webhook credentials.');
        }

        // Mailjet's message IDs are around 2^54, which a 64-bit int holds, but a bigger one is kept as text.
        $data = json_decode($body, true, 512, JSON_BIGINT_AS_STRING);

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
     * Swap any of Mailjet's headers the client sent for one carrying our ID.
     *
     * @param OutgoingEmail $email - the email.
     *
     * @return RawEmail
     */
    protected function prepareForSending(OutgoingEmail $email) : RawEmail
    {
        return $email->getRawEmail()
            ->withoutHeadersStartingWith('X-MJ-')
            ->withoutHeadersStartingWith('X-Mailjet-')
            ->withHeaderPrepended('X-MJ-CustomID', $email->getMessageId());
    }


    /**
     * Convert one of Mailjet's events into a ProviderEvent. Its "sent" event means the recipient's server accepted
     * the email, so it's a delivery. A bounce that isn't a hard bounce will be tried again.
     *
     * @param array $event - the event, decoded from JSON.
     *
     * @return ProviderEvent
     */
    private static function convertEvent(array $event) : ProviderEvent
    {
        $name = (string)$event['event'];
        $isHardBounce = (($event['hard_bounce'] ?? false) === true);

        $type = match (true) {
            $name === 'sent' => EmailEventType::DELIVERED,
            $name === 'bounce' && $isHardBounce => EmailEventType::BOUNCED,
            $name === 'bounce' => EmailEventType::DEFERRED,
            $name === 'blocked' => EmailEventType::DROPPED,
            $name === 'spam' => EmailEventType::SPAM_REPORT,
            $name === 'open' => EmailEventType::OPENED,
            $name === 'click' => EmailEventType::CLICKED,
            $name === 'unsub' => EmailEventType::UNSUBSCRIBED,
            default => EmailEventType::OTHER,
        };

        $detailParts = ($type === EmailEventType::OTHER) ? [$name] : [];

        foreach (['error_related_to', 'error', 'comment', 'smtp_reply', 'url'] as $field)
        {
            if (isset($event[$field]) && is_scalar($event[$field]) && trim((string)$event[$field]) !== '')
            {
                $detailParts[] = trim((string)$event[$field]);
            }
        }

        $recipient = (string)($event['email'] ?? '');
        $hasTime = (isset($event['time']) && is_numeric($event['time']));
        $time = $hasTime ? (int)$event['time'] : time();
        $mailjetId = (string)($event['MessageID'] ?? '');

        return new ProviderEvent(
            messageId: (string)($event['CustomID'] ?? ''),
            // Mailjet's events have no ID of their own, so they're told apart by what happened to whom, and when.
            providerEventId: "{$name}:{$mailjetId}:{$recipient}:{$time}",
            type: $type,
            recipient: $recipient,
            occurredAt: (new DateTimeImmutable())->setTimestamp($time),
            detail: implode(' - ', $detailParts),
        );
    }
}
