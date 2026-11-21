<?php

/*
 * SMTP2GO's webhook, which both ways of sending through it share (Smtp2GoProvider and Smtp2GoApiProvider). SMTP2GO
 * doesn't sign its webhooks, so they're checked with the webhook_secret (see WebhookBasicAuth), which is the password
 * in the webhook's address.
 *
 * Each event carries SMTP2GO's own ID for the email (email_id), which is what's saved when the email is sent, so
 * that's what it's matched back by. A webhook can be sent as JSON or form encoded, and both are read.
 */

declare(strict_types = 1);

use Psr\Http\Message\ServerRequestInterface;


final readonly class Smtp2GoWebhook
{
    // The events that are recorded, in SMTP2GO's names, and the ones without which deliveries and bounces aren't.
    public const array RECORDED_EVENTS = [
        'processed', 'delivered', 'bounce', 'reject', 'spam', 'unsubscribe', 'open', 'click',
    ];

    public const array ESSENTIAL_EVENTS = ['delivered', 'bounce', 'reject'];

    private string $m_secret;


    /**
     * @param string $providerName - the provider's name in settings.yaml, for the error message.
     *
     * @param string $secret - the secret the webhook authenticates with, or an empty string to leave it switched off.
     *
     * @throws ConfigurationException - if the secret is too short.
     */
    public function __construct(string $providerName, string $secret)
    {
        WebhookBasicAuth::assertValidSecret($providerName, $secret);
        $this->m_secret = $secret;
    }


    public function isEnabled() : bool
    {
        return $this->m_secret !== '';
    }


    /**
     * Check a webhook request has the secret, and get its events.
     *
     * @param string $body - the raw request body: JSON (one event or a list of them), or form encoded.
     *
     * @param ServerRequestInterface $request - the request, for its Authorization header.
     *
     * @return ProviderEventCollection
     *
     * @throws RequestRejectedException - if it doesn't have the secret, or the webhook isn't enabled.
     *
     * @throws InvalidInputException - if the body isn't an event.
     */
    public function parse(string $body, ServerRequestInterface $request) : ProviderEventCollection
    {
        if (WebhookBasicAuth::isAuthorised($request, $this->m_secret) === false)
        {
            throw new RequestRejectedException(RejectionReason::INVALID_CREDENTIALS, 'Invalid webhook credentials.');
        }

        $events = [];

        foreach (self::readEntries($body) as $entry)
        {
            // Its SMS events aren't about an email.
            if (is_string($entry['event'] ?? null) && !str_starts_with($entry['event'], 'sms_'))
            {
                $events[] = self::convertEvent($entry);
            }
        }

        return new ProviderEventCollection(...$events);
    }


    /**
     * Get the webhooks in a response from SMTP2GO's /webhook/view, whose documentation only shows a single webhook,
     * so a list of them, or a list under "webhooks", are read too.
     *
     * @param array $response - the response, decoded from JSON.
     *
     * @return list<array> - each webhook.
     */
    public static function extractConfigured(array $response) : array
    {
        $data = is_array($response['data'] ?? null) ? $response['data'] : [];

        $entries = match (true) {
            is_array($data['webhooks'] ?? null) => $data['webhooks'],
            array_is_list($data) => $data,
            default => [$data],
        };

        $webhooks = [];

        foreach ($entries as $entry)
        {
            if (is_array($entry) && is_string($entry['url'] ?? null))
            {
                $webhooks[] = $entry;
            }
        }

        return $webhooks;
    }


    /**
     * Read a request's body into its events' fields.
     *
     * @param string $body - the raw request body.
     *
     * @return list<array>
     *
     * @throws InvalidInputException - if it's neither JSON nor a form with an event.
     */
    private static function readEntries(string $body) : array
    {
        $decoded = json_decode($body, true);

        if (is_array($decoded))
        {
            $entries = array_is_list($decoded) ? $decoded : [$decoded];
        }
        else
        {
            parse_str($body, $form);

            if (!is_string($form['event'] ?? null))
            {
                throw new InvalidInputException('Expected an SMTP2GO event, as JSON or form encoded.');
            }

            $entries = [$form];
        }

        $valid = [];

        foreach ($entries as $entry)
        {
            if (is_array($entry))
            {
                $valid[] = $entry;
            }
        }

        return $valid;
    }


    /**
     * Convert one of SMTP2GO's events into a ProviderEvent. A soft bounce will be tried again, and a rejected email
     * wasn't sent.
     *
     * @param array $entry - the event's fields.
     *
     * @return ProviderEvent
     */
    private static function convertEvent(array $entry) : ProviderEvent
    {
        $name = (string)$entry['event'];
        $isSoftBounce = ($name === 'bounce' && strtolower(self::readText($entry['bounce'] ?? '')) === 'soft');

        $type = match ($name) {
            'processed' => EmailEventType::PROCESSED,
            'delivered' => EmailEventType::DELIVERED,
            'bounce' => $isSoftBounce ? EmailEventType::DEFERRED : EmailEventType::BOUNCED,
            'reject' => EmailEventType::DROPPED,
            'spam' => EmailEventType::SPAM_REPORT,
            'unsubscribe' => EmailEventType::UNSUBSCRIBED,
            'open' => EmailEventType::OPENED,
            'click' => EmailEventType::CLICKED,
            default => EmailEventType::OTHER,
        };

        $detailParts = ($type === EmailEventType::OTHER) ? [$name] : [];

        foreach (['message', 'context', 'host'] as $field)
        {
            $text = self::readText($entry[$field] ?? '');

            if ($text !== '')
            {
                $detailParts[] = $text;
            }
        }

        $emailId = self::readText($entry['email_id'] ?? '');
        $recipient = self::readText($entry['rcpt'] ?? '');
        $time = self::readText($entry['time'] ?? '');

        return new ProviderEvent(
            messageId: '',
            // SMTP2GO's events have no ID of their own, so they're told apart by what happened to whom, and when.
            providerEventId: hash('sha256', implode("\0", [$name, $emailId, $recipient, $time])),
            type: $type,
            recipient: $recipient,
            occurredAt: self::readOccurredAt($time),
            detail: implode(' - ', $detailParts),
            providerMessageId: $emailId,
        );
    }


    /**
     * Read when an event happened. SMTP2GO calls it a UTC timestamp, without saying in what format, so a Unix
     * timestamp and any date PHP can read are both taken.
     *
     * @param string $time - the event's time.
     *
     * @return DateTimeImmutable - when it happened, or now if it can't be read.
     */
    private static function readOccurredAt(string $time) : DateTimeImmutable
    {
        $utc = new DateTimeZone('UTC');

        try
        {
            $occurredAt = is_numeric($time)
                ? (new DateTimeImmutable('now', $utc))->setTimestamp((int)$time)
                : new DateTimeImmutable(($time === '') ? 'now' : $time, $utc);
        }
        catch (Exception)
        {
            $occurredAt = new DateTimeImmutable('now', $utc);
        }

        return $occurredAt;
    }


    /**
     * Read a field as text. SMTP2GO doesn't document each field's type, so for a list, its first item is used.
     *
     * @param mixed $value - the field, which could be anything, as it's from a request.
     *
     * @return string
     */
    private static function readText(mixed $value) : string
    {
        $first = (is_array($value) && count($value) > 0) ? reset($value) : $value;
        return is_scalar($first) ? trim((string)$first) : '';
    }
}
