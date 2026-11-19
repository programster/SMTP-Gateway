<?php

/*
 * Sends email through MailerSend's SMTP relay (smtp.mailersend.net), logging in as one of its SMTP users. Any SMTP
 * user can send as any of the account's verified domains.
 *
 * MailerSend drops every custom header except X-MailerSend-Tags, so our ID for the email is sent as one of its tags,
 * which MailerSend passes back in its webhooks. Every X-MailerSend-* header the client sent is removed first, so it
 * can't pass off its events as another email's, or use up the 5 tags MailerSend allows.
 *
 * MailerSend signs its webhooks with each webhook's signing secret: an HMAC-SHA256 of the request body, in its
 * Signature header.
 */

declare(strict_types = 1);

use Psr\Http\Message\ServerRequestInterface;
use Ramsey\Uuid\Uuid;


final class MailerSendProvider extends SmtpRelayProvider implements ProviderWebhookInterface
{
    private readonly string $m_webhookSecret;


    /**
     * @param string $name - the provider's name in settings.yaml. Its webhook is at /webhooks/<name>.
     *
     * @param string $smtpUsername - one of the account's SMTP users' usernames, e.g. "MS_bGQ0nV@example.org".
     *
     * @param string $smtpPassword - its password.
     *
     * @param string $webhookSecret - the webhook's signing secret, or an empty string to leave the webhook switched
     * off.
     *
     * @throws ConfigurationException - if the username or password is empty.
     */
    public function __construct(string $name, string $smtpUsername, string $smtpPassword, string $webhookSecret)
    {
        if (trim($smtpUsername) === '' || trim($smtpPassword) === '')
        {
            $msg = "The MailerSend provider \"{$name}\" needs an smtp_username and smtp_password.";
            throw new ConfigurationException($msg);
        }

        $host = 'smtp.mailersend.net';
        $relaySettings = new SmtpRelaySettings($host, 587, trim($smtpUsername), $smtpPassword, SmtpSecurity::TLS);
        parent::__construct($name, $relaySettings);
        $this->m_webhookSecret = trim($webhookSecret);
    }


    public function getType() : ProviderType
    {
        return ProviderType::MAILERSEND;
    }


    public function isWebhookEnabled() : bool
    {
        return $this->m_webhookSecret !== '';
    }


    /**
     * Check a webhook request is signed with the signing secret, and get its event.
     *
     * @param string $body - the raw request body: {"type": ..., "created_at": ..., "data": {...}}.
     *
     * @param ServerRequestInterface $request - the request, for its Signature header.
     *
     * @return ProviderEventCollection - the one event it holds (none for MailerSend's test request).
     *
     * @throws RequestRejectedException - if the signature is missing or wrong, or the webhook isn't enabled.
     *
     * @throws InvalidInputException - if the body isn't the JSON MailerSend sends.
     */
    public function parseWebhook(string $body, ServerRequestInterface $request) : ProviderEventCollection
    {
        $signature = strtolower(trim($request->getHeaderLine('Signature')));
        $expected = hash_hmac('sha256', $body, $this->m_webhookSecret);

        if ($this->isWebhookEnabled() === false || $signature === '' || hash_equals($expected, $signature) === false)
        {
            throw new RequestRejectedException(RejectionReason::INVALID_CREDENTIALS, 'Invalid webhook signature.');
        }

        $data = json_decode($body, true);

        if (!is_array($data) || !is_string($data['type'] ?? null))
        {
            throw new InvalidInputException('Expected a JSON object with a "type".');
        }

        $events = [];

        if (str_starts_with($data['type'], 'activity.') && is_array($data['data'] ?? null))
        {
            $events[] = self::convertEvent($data['data'], (string)($data['created_at'] ?? ''));
        }

        return new ProviderEventCollection(...$events);
    }


    /**
     * Swap any X-MailerSend-* header the client sent for a tag carrying our ID.
     *
     * @param OutgoingEmail $email - the email.
     *
     * @return RawEmail
     */
    protected function prepareForSending(OutgoingEmail $email) : RawEmail
    {
        return $email->getRawEmail()
            ->withoutHeadersStartingWith('X-MailerSend-')
            ->withHeaderPrepended('X-MailerSend-Tags', $email->getMessageId());
    }


    /**
     * Convert one of MailerSend's activity events into a ProviderEvent. Our ID is the tag that's a UUID.
     *
     * @param array $activity - the event's "data", decoded from JSON.
     *
     * @param string $createdAt - when it happened, e.g. "2025-08-05T21:24:02.000000Z".
     *
     * @return ProviderEvent
     */
    private static function convertEvent(array $activity, string $createdAt) : ProviderEvent
    {
        $name = (string)($activity['type'] ?? '');

        $type = match ($name) {
            'sent' => EmailEventType::PROCESSED,
            'delivered' => EmailEventType::DELIVERED,
            'soft_bounced', 'deferred' => EmailEventType::DEFERRED,
            'hard_bounced' => EmailEventType::BOUNCED,
            'suppressed' => EmailEventType::DROPPED,
            'opened', 'opened_unique' => EmailEventType::OPENED,
            'clicked', 'clicked_unique' => EmailEventType::CLICKED,
            'spam_complaint' => EmailEventType::SPAM_REPORT,
            'unsubscribed' => EmailEventType::UNSUBSCRIBED,
            default => EmailEventType::OTHER,
        };

        $messageId = '';

        foreach (is_array($activity['tags'] ?? null) ? $activity['tags'] : [] as $tag)
        {
            if ($messageId === '' && is_string($tag) && Uuid::isValid($tag))
            {
                $messageId = strtolower($tag);
            }
        }

        $meta = is_array($activity['meta'] ?? null) ? $activity['meta'] : [];
        $detailParts = ($type === EmailEventType::OTHER) ? [$name] : [];

        foreach (['bounce_reason', 'suppression_reason', 'unsubscribe_reason', 'url'] as $field)
        {
            if (isset($meta[$field]) && is_scalar($meta[$field]) && trim((string)$meta[$field]) !== '')
            {
                $detailParts[] = trim((string)$meta[$field]);
            }
        }

        try
        {
            $occurredAt = ($createdAt === '') ? new DateTimeImmutable() : new DateTimeImmutable($createdAt);
        }
        catch (Exception)
        {
            $occurredAt = new DateTimeImmutable();
        }

        return new ProviderEvent(
            messageId: $messageId,
            providerEventId: (string)($activity['id'] ?? ''),
            type: $type,
            recipient: (string)($activity['recipient'] ?? ''),
            occurredAt: $occurredAt,
            detail: implode(' - ', $detailParts),
        );
    }
}
