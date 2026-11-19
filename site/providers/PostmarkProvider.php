<?php

/*
 * Sends email through Postmark's SMTP relay (smtp.postmarkapp.com), logging in with a server's API token, into one
 * of its message streams.
 *
 * Every X-PM-* header the client sent is removed (they choose the message stream, tag it, keep its Message-ID, and
 * switch tracking on or off), and replaced with the stream this provider sends into, and an X-PM-Metadata-* header
 * carrying our ID for the email, which Postmark passes back in its webhooks (and doesn't deliver).
 *
 * Postmark doesn't sign its webhooks, so they're checked with HTTP Basic authentication instead (see
 * WebhookBasicAuth): set the webhook's address in Postmark to the one shown in the sender checks.
 */

declare(strict_types = 1);

use Psr\Http\Message\ServerRequestInterface;


final class PostmarkProvider extends SmtpRelayProvider implements ProviderWebhookInterface
{
    // The metadata field holding our ID (Postmark allows names of up to 20 characters).
    public const string MESSAGE_ID_METADATA = 'smtp_api_message_id';

    private readonly string $m_messageStream;
    private readonly string $m_webhookSecret;


    /**
     * @param string $name - the provider's name in settings.yaml. Its webhook is at /webhooks/<name>.
     *
     * @param string $serverToken - the Postmark server's API token, which is also its SMTP username and password.
     *
     * @param string $messageStream - the ID of the message stream to send into, e.g. "outbound".
     *
     * @param string $webhookSecret - the secret Postmark's webhooks authenticate with, or an empty string to leave
     * the webhook switched off.
     *
     * @throws ConfigurationException - if the token or stream is empty, or the secret is too short.
     */
    public function __construct(string $name, string $serverToken, string $messageStream, string $webhookSecret)
    {
        if (trim($serverToken) === '')
        {
            throw new ConfigurationException("The Postmark provider \"{$name}\" needs a server_token.");
        }

        if (preg_match('/^[a-z0-9_-]+$/i', $messageStream) !== 1)
        {
            $msg = "The Postmark provider \"{$name}\"'s message_stream must be a stream's ID, e.g. \"outbound\".";
            throw new ConfigurationException($msg);
        }

        WebhookBasicAuth::assertValidSecret($name, $webhookSecret);
        $token = trim($serverToken);
        $relaySettings = new SmtpRelaySettings('smtp.postmarkapp.com', 587, $token, $token, SmtpSecurity::TLS);
        parent::__construct($name, $relaySettings);
        $this->m_messageStream = $messageStream;
        $this->m_webhookSecret = $webhookSecret;
    }


    public function getType() : ProviderType
    {
        return ProviderType::POSTMARK;
    }


    public function isWebhookEnabled() : bool
    {
        return $this->m_webhookSecret !== '';
    }


    /**
     * Check a webhook request has the secret, and get its event.
     *
     * @param string $body - the raw request body: one event, as a JSON object.
     *
     * @param ServerRequestInterface $request - the request, for its Authorization header.
     *
     * @return ProviderEventCollection - the one event it holds.
     *
     * @throws RequestRejectedException - if it doesn't have the secret, or the webhook isn't enabled.
     *
     * @throws InvalidInputException - if the body isn't the JSON Postmark sends.
     */
    public function parseWebhook(string $body, ServerRequestInterface $request) : ProviderEventCollection
    {
        if (WebhookBasicAuth::isAuthorised($request, $this->m_webhookSecret) === false)
        {
            throw new RequestRejectedException(RejectionReason::INVALID_CREDENTIALS, 'Invalid webhook credentials.');
        }

        $event = json_decode($body, true);

        if (!is_array($event) || !is_string($event['RecordType'] ?? null))
        {
            throw new InvalidInputException('Expected a JSON object with a "RecordType".');
        }

        return new ProviderEventCollection(self::convertEvent($event));
    }


    /**
     * Swap any X-PM-* header the client sent for the stream to send into, and our ID.
     *
     * @param OutgoingEmail $email - the email.
     *
     * @return RawEmail
     */
    protected function prepareForSending(OutgoingEmail $email) : RawEmail
    {
        return $email->getRawEmail()
            ->withoutHeadersStartingWith('X-PM-')
            ->withHeaderPrepended('X-PM-Metadata-' . self::MESSAGE_ID_METADATA, $email->getMessageId())
            ->withHeaderPrepended('X-PM-Message-Stream', $this->m_messageStream);
    }


    /**
     * Convert one of Postmark's webhook events into a ProviderEvent. A bounce's Type says what kind it was: a
     * "Transient" one means delivery has been delayed, and will be retried.
     *
     * @param array $event - the event, decoded from JSON.
     *
     * @return ProviderEvent
     */
    private static function convertEvent(array $event) : ProviderEvent
    {
        $recordType = (string)$event['RecordType'];
        $bounceType = (string)($event['Type'] ?? '');
        $isUnsubscribe = (($event['SuppressSending'] ?? false) === true);

        $type = match (true) {
            $recordType === 'Delivery' => EmailEventType::DELIVERED,
            $recordType === 'Bounce' && $bounceType === 'Transient' => EmailEventType::DEFERRED,
            $recordType === 'Bounce' && $bounceType === 'SpamComplaint' => EmailEventType::SPAM_REPORT,
            $recordType === 'Bounce' => EmailEventType::BOUNCED,
            $recordType === 'SpamComplaint' => EmailEventType::SPAM_REPORT,
            $recordType === 'Open' => EmailEventType::OPENED,
            $recordType === 'Click' => EmailEventType::CLICKED,
            $recordType === 'SubscriptionChange' && $isUnsubscribe => EmailEventType::UNSUBSCRIBED,
            default => EmailEventType::OTHER,
        };

        $detailParts = ($type === EmailEventType::OTHER) ? [$recordType] : [];

        foreach (['Type', 'Description', 'Details', 'SuppressionReason', 'OriginalLink'] as $field)
        {
            if (isset($event[$field]) && is_scalar($event[$field]) && trim((string)$event[$field]) !== '')
            {
                $detailParts[] = trim((string)$event[$field]);
            }
        }

        $recipient = (string)($event['Recipient'] ?? ($event['Email'] ?? ''));
        $when = '';

        // Each type names the time it happened differently.
        foreach (['DeliveredAt', 'BouncedAt', 'ReceivedAt', 'ChangedAt'] as $field)
        {
            if ($when === '' && is_string($event[$field] ?? null))
            {
                $when = $event[$field];
            }
        }

        $occurredAt = self::parseTime($when);
        $metadata = is_array($event['Metadata'] ?? null) ? $event['Metadata'] : [];
        $postmarkId = (string)($event['MessageID'] ?? '');

        // Only bounces have an ID of their own, so the others are told apart by what happened to whom, and when.
        $eventId = isset($event['ID'])
            ? "{$recordType}:{$event['ID']}"
            : "{$recordType}:{$postmarkId}:{$recipient}:{$when}";

        return new ProviderEvent(
            messageId: (string)($metadata[self::MESSAGE_ID_METADATA] ?? ''),
            providerEventId: $eventId,
            type: $type,
            recipient: $recipient,
            occurredAt: $occurredAt,
            detail: implode(' - ', $detailParts),
        );
    }


    /**
     * Read one of Postmark's ISO 8601 times, e.g. "2026-11-05T16:33:54.9070259Z", which can have more fractional
     * digits than PHP reads, or now if it can't be read.
     */
    private static function parseTime(string $time) : DateTimeImmutable
    {
        $trimmed = preg_replace('/(\.\d{6})\d+/', '$1', $time);

        try
        {
            $parsed = ($trimmed === '') ? new DateTimeImmutable() : new DateTimeImmutable($trimmed);
        }
        catch (Exception)
        {
            $parsed = new DateTimeImmutable();
        }

        return $parsed;
    }
}
