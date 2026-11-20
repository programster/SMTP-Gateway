<?php

/*
 * Sends email through Mailgun's SMTP relay, logging in with one of a sending domain's SMTP credentials (e.g.
 * postmaster@mg.example.org). Mailgun DKIM-signs everything sent with them as that domain.
 *
 * Each email gets an X-Mailgun-Variables header carrying our ID for it, which Mailgun passes back in its webhooks
 * (as "user-variables"), so deliveries, bounces, opens and clicks can be recorded against it. Every X-Mailgun-*
 * header the client sent is removed first: they control how Mailgun sends the email (e.g. X-Mailgun-Archive-To
 * posts a copy of it to any URL, X-Mailgun-Drop-Message doesn't send it at all, and X-Mailgun-Variables would let a
 * client pass off its events as another email's).
 *
 * Mailgun signs its webhooks with the account's HTTP webhook signing key (Settings → API Security), which is checked
 * as https://documentation.mailgun.com/docs/mailgun/user-manual/webhooks/securing-webhooks describes.
 */

declare(strict_types = 1);

use Psr\Http\Message\ServerRequestInterface;


final class MailgunProvider extends SmtpRelayProvider implements ProviderWebhookInterface
{
    public const string MESSAGE_ID_VARIABLE = 'smtp_gateway_message_id';

    private readonly string $m_webhookSigningKey;


    /**
     * @param string $name - the provider's name in settings.yaml. Its webhook is at /webhooks/<name>.
     *
     * @param MailgunRegion $region - the region the domain is in.
     *
     * @param string $smtpUsername - the domain's SMTP login, e.g. "postmaster@mg.example.org".
     *
     * @param string $smtpPassword - its password.
     *
     * @param string $webhookSigningKey - the account's HTTP webhook signing key, or an empty string to leave the
     * webhook switched off.
     *
     * @throws ConfigurationException - if the login isn't an address at the domain, or the password is empty.
     */
    public function __construct(
        string $name,
        MailgunRegion $region,
        string $smtpUsername,
        string $smtpPassword,
        string $webhookSigningKey
    )
    {
        if (self::extractDomain($smtpUsername) === '')
        {
            $msg =
                "The Mailgun provider \"{$name}\"'s smtp_username must be one of its domain's SMTP logins, e.g."
                . ' postmaster@mg.example.org.';


            throw new ConfigurationException($msg);
        }

        if (trim($smtpPassword) === '')
        {
            throw new ConfigurationException("The Mailgun provider \"{$name}\" needs an smtp_password.");
        }

        $relaySettings = new SmtpRelaySettings(
            $region->getSmtpHost(),
            587,
            trim($smtpUsername),
            $smtpPassword,
            SmtpSecurity::TLS
        );

        parent::__construct($name, $relaySettings);
        $this->m_webhookSigningKey = trim($webhookSigningKey);
    }


    /**
     * Get the domain of an SMTP login, which is the Mailgun domain it sends as.
     *
     * @param string $smtpUsername - the login, e.g. "postmaster@mg.example.org".
     *
     * @return string - the domain, e.g. "mg.example.org", in lowercase, or an empty string if it isn't an address.
     */
    public static function extractDomain(string $smtpUsername) : string
    {
        $isAddress = (preg_match('/^[^@\s]+@([a-z0-9.-]+\.[a-z]{2,})$/i', trim($smtpUsername), $matches) === 1);
        return $isAddress ? strtolower($matches[1]) : '';
    }


    public function getType() : ProviderType
    {
        return ProviderType::MAILGUN;
    }


    public function isWebhookEnabled() : bool
    {
        return $this->m_webhookSigningKey !== '';
    }


    /**
     * Check a request to the webhook is genuinely from Mailgun, and get its event.
     *
     * @param string $body - the raw request body: {"signature": {...}, "event-data": {...}}.
     *
     * @param ServerRequestInterface $request - the request.
     *
     * @return ProviderEventCollection - the one event it holds.
     *
     * @throws RequestRejectedException - if the signature is missing or wrong, or the webhook isn't enabled.
     *
     * @throws InvalidInputException - if the body isn't the JSON Mailgun sends.
     */
    public function parseWebhook(string $body, ServerRequestInterface $request) : ProviderEventCollection
    {
        $data = json_decode($body, true);
        $signature = (is_array($data) && is_array($data['signature'] ?? null)) ? $data['signature'] : [];
        $timestamp = (string)($signature['timestamp'] ?? '');
        $token = (string)($signature['token'] ?? '');
        $expected = hash_hmac('sha256', $timestamp . $token, $this->m_webhookSigningKey);

        if (
               $this->isWebhookEnabled() === false
            || $timestamp === ''
            || $token === ''
            || !is_string($signature['signature'] ?? null)
            || hash_equals($expected, strtolower($signature['signature'])) === false
        )
        {
            throw new RequestRejectedException(RejectionReason::INVALID_CREDENTIALS, 'Invalid webhook signature.');
        }

        if (!is_array($data['event-data'] ?? null))
        {
            throw new InvalidInputException('Expected an "event-data" object.');
        }

        return new ProviderEventCollection(self::convertEvent($data['event-data']));
    }


    /**
     * Swap any X-Mailgun-* header the client sent for one carrying our ID for the email.
     *
     * @param OutgoingEmail $email - the email.
     *
     * @return RawEmail
     */
    protected function prepareForSending(OutgoingEmail $email) : RawEmail
    {
        $variables = json_encode([self::MESSAGE_ID_VARIABLE => $email->getMessageId()]);

        return $email->getRawEmail()
            ->withoutHeadersStartingWith('X-Mailgun-')
            ->withHeaderPrepended('X-Mailgun-Variables', $variables);
    }


    /**
     * Convert one of Mailgun's events into a ProviderEvent. Both kinds of failure are "failed" events, told apart
     * by their severity: a temporary one will be retried (so is a deferral), and a permanent one is a bounce, unless
     * Mailgun didn't try at all because the address is on a suppression list.
     *
     * @param array $event - the "event-data", decoded from JSON.
     *
     * @return ProviderEvent
     */
    private static function convertEvent(array $event) : ProviderEvent
    {
        $name = (string)($event['event'] ?? '');
        $severity = (string)($event['severity'] ?? '');
        $reason = (string)($event['reason'] ?? '');
        $isSuppressed = str_starts_with($reason, 'suppress-');

        $type = match (true) {
            $name === 'accepted' => EmailEventType::PROCESSED,
            $name === 'delivered' => EmailEventType::DELIVERED,
            $name === 'failed' && $severity === 'temporary' => EmailEventType::DEFERRED,
            $name === 'failed' && $isSuppressed => EmailEventType::DROPPED,
            $name === 'failed' => EmailEventType::BOUNCED,
            $name === 'rejected' => EmailEventType::DROPPED,
            $name === 'opened' => EmailEventType::OPENED,
            $name === 'clicked' => EmailEventType::CLICKED,
            $name === 'complained' => EmailEventType::SPAM_REPORT,
            $name === 'unsubscribed' => EmailEventType::UNSUBSCRIBED,
            default => EmailEventType::OTHER,
        };

        $status = is_array($event['delivery-status'] ?? null) ? $event['delivery-status'] : [];
        $detailParts = ($type === EmailEventType::OTHER) ? [$name] : [];

        foreach ([$reason, $status['code'] ?? '', $status['message'] ?? '', $status['description'] ?? ''] as $part)
        {
            if (is_scalar($part) && trim((string)$part) !== '')
            {
                $detailParts[] = trim((string)$part);
            }
        }

        if (isset($event['url']) && is_string($event['url']))
        {
            $detailParts[] = $event['url'];
        }

        $variables = is_array($event['user-variables'] ?? null) ? $event['user-variables'] : [];
        $hasTimestamp = (isset($event['timestamp']) && is_numeric($event['timestamp']));
        $timestamp = $hasTimestamp ? (int)$event['timestamp'] : time();

        return new ProviderEvent(
            messageId: (string)($variables[self::MESSAGE_ID_VARIABLE] ?? ''),
            providerEventId: (string)($event['id'] ?? ''),
            type: $type,
            recipient: (string)($event['recipient'] ?? ''),
            occurredAt: (new DateTimeImmutable())->setTimestamp($timestamp),
            detail: implode(' - ', array_unique($detailParts)),
        );
    }
}
