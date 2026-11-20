<?php

/*
 * Sends email through Mailtrap's live (transactional) SMTP relay, live.smtp.mailtrap.io, logging in as "api" with
 * one of a sending domain's API tokens.
 *
 * Every X-MT-* header the client sent is removed, and replaced with an X-MT-Custom-Variables header carrying our ID
 * for the email, which Mailtrap passes back in its webhooks (as "custom_variables").
 *
 * Mailtrap signs its webhooks with each webhook's signing secret: an HMAC-SHA256 of the request body, in its
 * Mailtrap-Signature header. It sends them in batches, as {"events": [...]}.
 */

declare(strict_types = 1);

use Psr\Http\Message\ServerRequestInterface;


final class MailtrapProvider extends SmtpRelayProvider implements ProviderWebhookInterface
{
    public const string MESSAGE_ID_VARIABLE = 'smtp_gateway_message_id';

    private readonly string $m_webhookSecret;


    /**
     * @param string $name - the provider's name in settings.yaml. Its webhook is at /webhooks/<name>.
     *
     * @param string $smtpToken - one of the sending domain's API tokens, which is its SMTP password.
     *
     * @param string $webhookSecret - the webhook's signing secret, or an empty string to leave the webhook switched
     * off.
     *
     * @throws ConfigurationException - if the token is empty.
     */
    public function __construct(string $name, string $smtpToken, string $webhookSecret)
    {
        if (trim($smtpToken) === '')
        {
            throw new ConfigurationException("The Mailtrap provider \"{$name}\" needs an smtp_token.");
        }

        $host = 'live.smtp.mailtrap.io';
        $relaySettings = new SmtpRelaySettings($host, 587, 'api', trim($smtpToken), SmtpSecurity::TLS);
        parent::__construct($name, $relaySettings);
        $this->m_webhookSecret = trim($webhookSecret);
    }


    public function getType() : ProviderType
    {
        return ProviderType::MAILTRAP;
    }


    public function isWebhookEnabled() : bool
    {
        return $this->m_webhookSecret !== '';
    }


    /**
     * Check a webhook request is signed with the signing secret, and get its events.
     *
     * @param string $body - the raw request body: {"events": [...]}.
     *
     * @param ServerRequestInterface $request - the request, for its Mailtrap-Signature header.
     *
     * @return ProviderEventCollection
     *
     * @throws RequestRejectedException - if the signature is missing or wrong, or the webhook isn't enabled.
     *
     * @throws InvalidInputException - if the body isn't the JSON Mailtrap sends (e.g. it's set to send JSON Lines).
     */
    public function parseWebhook(string $body, ServerRequestInterface $request) : ProviderEventCollection
    {
        $signature = strtolower(trim($request->getHeaderLine('Mailtrap-Signature')));
        $expected = hash_hmac('sha256', $body, $this->m_webhookSecret);

        if ($this->isWebhookEnabled() === false || $signature === '' || hash_equals($expected, $signature) === false)
        {
            throw new RequestRejectedException(RejectionReason::INVALID_CREDENTIALS, 'Invalid webhook signature.');
        }

        $data = json_decode($body, true);

        if (!is_array($data) || !is_array($data['events'] ?? null))
        {
            throw new InvalidInputException('Expected {"events": [...]}: set the webhook\'s payload format to JSON.');
        }

        $events = [];

        foreach ($data['events'] as $event)
        {
            if (is_array($event) && is_string($event['event'] ?? null))
            {
                $events[] = self::convertEvent($event);
            }
        }

        return new ProviderEventCollection(...$events);
    }


    /**
     * Swap any X-MT-* header the client sent for one carrying our ID.
     *
     * @param OutgoingEmail $email - the email.
     *
     * @return RawEmail
     */
    protected function prepareForSending(OutgoingEmail $email) : RawEmail
    {
        $variables = json_encode([self::MESSAGE_ID_VARIABLE => $email->getMessageId()]);

        return $email->getRawEmail()
            ->withoutHeadersStartingWith('X-MT-')
            ->withHeaderPrepended('X-MT-Custom-Variables', $variables);
    }


    /**
     * Convert one of Mailtrap's events into a ProviderEvent. A soft bounce will be tried again, and a suspension or
     * reject means Mailtrap didn't send it.
     *
     * @param array $event - the event, decoded from JSON.
     *
     * @return ProviderEvent
     */
    private static function convertEvent(array $event) : ProviderEvent
    {
        $name = (string)$event['event'];

        $type = match ($name) {
            'delivery' => EmailEventType::DELIVERED,
            'soft bounce', 'soft_bounce' => EmailEventType::DEFERRED,
            'bounce' => EmailEventType::BOUNCED,
            'suspension', 'reject' => EmailEventType::DROPPED,
            'open' => EmailEventType::OPENED,
            'click' => EmailEventType::CLICKED,
            'spam', 'spam_complaint' => EmailEventType::SPAM_REPORT,
            'unsubscribe' => EmailEventType::UNSUBSCRIBED,
            default => EmailEventType::OTHER,
        };

        $detailParts = ($type === EmailEventType::OTHER) ? [$name] : [];

        foreach (['reason', 'response_code', 'response', 'bounce_category', 'url'] as $field)
        {
            if (isset($event[$field]) && is_scalar($event[$field]) && trim((string)$event[$field]) !== '')
            {
                $detailParts[] = trim((string)$event[$field]);
            }
        }

        $variables = is_array($event['custom_variables'] ?? null) ? $event['custom_variables'] : [];
        $hasTimestamp = (isset($event['timestamp']) && is_numeric($event['timestamp']));
        $timestamp = $hasTimestamp ? (int)$event['timestamp'] : time();

        return new ProviderEvent(
            messageId: (string)($variables[self::MESSAGE_ID_VARIABLE] ?? ''),
            providerEventId: (string)($event['event_id'] ?? ''),
            type: $type,
            recipient: (string)($event['email'] ?? ''),
            occurredAt: (new DateTimeImmutable())->setTimestamp($timestamp),
            detail: implode(' - ', $detailParts),
        );
    }
}
