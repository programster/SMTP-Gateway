<?php

/*
 * Sends email through SendGrid's SMTP relay (smtp.sendgrid.net), which, unlike SendGrid's v3 web API, accepts the
 * raw email as-is.
 *
 * Each email gets an X-SMTPAPI header carrying our ID for it, which SendGrid passes back in its event webhook, so
 * deliveries, bounces, opens and clicks can be recorded against it. Any X-SMTPAPI header the client sent is
 * removed first: it can add recipients (and change other settings), which would get round the key's recipient
 * restrictions.
 */

declare(strict_types = 1);

use Psr\Http\Message\ServerRequestInterface;


final class SendGridProvider extends SmtpRelayProvider implements ProviderWebhookInterface
{
    public const string MESSAGE_ID_ARGUMENT = 'smtp_gateway_message_id';

    private readonly string $m_webhookPublicKey;


    /**
     * @param string $name - the provider's name in settings.yaml. Its event webhook is at /webhooks/<name>.
     *
     * @param string $apiKey - the SendGrid API key, which needs the "Mail Send" permission.
     *
     * @param string $webhookPublicKey - the public key from SendGrid's "Signed Event Webhook" settings (base64), or
     * an empty string to leave the event webhook switched off.
     *
     * @throws ConfigurationException - if the API key is empty, or the public key isn't valid.
     */
    public function __construct(string $name, string $apiKey, string $webhookPublicKey)
    {
        if (trim($apiKey) === '')
        {
            throw new ConfigurationException("The SendGrid provider \"{$name}\" needs an api_key.");
        }

        $pem = '';

        if (trim($webhookPublicKey) !== '')
        {
            $pem = self::convertPublicKeyToPem($name, $webhookPublicKey);
        }

        $relaySettings = new SmtpRelaySettings('smtp.sendgrid.net', 587, 'apikey', $apiKey, SmtpSecurity::TLS);
        parent::__construct($name, $relaySettings);
        $this->m_webhookPublicKey = $pem;
    }


    public function getType() : ProviderType
    {
        return ProviderType::SENDGRID;
    }


    public function isWebhookEnabled() : bool
    {
        return $this->m_webhookPublicKey !== '';
    }


    /**
     * Check a request to the event webhook is genuinely from SendGrid, and get its events.
     * See SendGrid's "Getting Started with the Event Webhook Security Features":
     * https://www.twilio.com/docs/sendgrid/for-developers/tracking-events/getting-started-event-webhook-security-features
     *
     * @param string $body - the raw request body.
     *
     * @param ServerRequestInterface $request - the request, for its signature headers.
     *
     * @return ProviderEventCollection
     *
     * @throws RequestRejectedException - if the signature is missing or wrong, or the webhook isn't enabled.
     *
     * @throws InvalidInputException - if the body isn't the JSON SendGrid sends.
     */
    public function parseWebhook(string $body, ServerRequestInterface $request) : ProviderEventCollection
    {
        $signature = base64_decode($request->getHeaderLine('X-Twilio-Email-Event-Webhook-Signature'), true);
        $timestamp = $request->getHeaderLine('X-Twilio-Email-Event-Webhook-Timestamp');

        if (
               $this->isWebhookEnabled() === false
            || $signature === false
            || $signature === ''
            || $timestamp === ''
            || openssl_verify($timestamp . $body, $signature, $this->m_webhookPublicKey, OPENSSL_ALGO_SHA256) !== 1
        )
        {
            throw new RequestRejectedException(RejectionReason::INVALID_CREDENTIALS, 'Invalid webhook signature.');
        }

        $events = json_decode($body, true);

        if (!is_array($events) || !array_is_list($events))
        {
            throw new InvalidInputException('Expected a JSON array of events.');
        }

        $collection = [];

        foreach ($events as $event)
        {
            if (is_array($event))
            {
                $collection[] = self::convertEvent($event);
            }
        }

        return new ProviderEventCollection(...$collection);
    }


    /**
     * Swap any X-SMTPAPI header the client sent for one carrying our ID for the email.
     *
     * @param OutgoingEmail $email - the email.
     *
     * @return RawEmail
     */
    protected function prepareForSending(OutgoingEmail $email) : RawEmail
    {
        $smtpApi = json_encode(['unique_args' => [self::MESSAGE_ID_ARGUMENT => $email->getMessageId()]]);
        return $email->getRawEmail()->withoutHeader('X-SMTPAPI')->withHeaderPrepended('X-SMTPAPI', $smtpApi);
    }


    /**
     * Convert one of SendGrid's events into a ProviderEvent.
     *
     * @param array $event - the event, decoded from JSON.
     *
     * @return ProviderEvent
     */
    private static function convertEvent(array $event) : ProviderEvent
    {
        $type = match ((string)($event['event'] ?? '')) {
            'processed' => EmailEventType::PROCESSED,
            'delivered' => EmailEventType::DELIVERED,
            'deferred' => EmailEventType::DEFERRED,
            'bounce' => EmailEventType::BOUNCED,
            'dropped' => EmailEventType::DROPPED,
            'open' => EmailEventType::OPENED,
            'click' => EmailEventType::CLICKED,
            'spamreport' => EmailEventType::SPAM_REPORT,
            'unsubscribe', 'group_unsubscribe' => EmailEventType::UNSUBSCRIBED,
            default => EmailEventType::OTHER,
        };

        $detailParts = [];

        foreach (['type', 'reason', 'response', 'status', 'url'] as $field)
        {
            if (isset($event[$field]) && is_scalar($event[$field]) && (string)$event[$field] !== '')
            {
                $detailParts[] = (string)$event[$field];
            }
        }

        if ($type === EmailEventType::OTHER)
        {
            array_unshift($detailParts, (string)($event['event'] ?? 'unknown'));
        }

        $hasTimestamp = (isset($event['timestamp']) && is_numeric($event['timestamp']));
        $timestamp = $hasTimestamp ? (int)$event['timestamp'] : time();

        return new ProviderEvent(
            messageId: (string)($event[self::MESSAGE_ID_ARGUMENT] ?? ''),
            providerEventId: (string)($event['sg_event_id'] ?? ''),
            type: $type,
            recipient: (string)($event['email'] ?? ''),
            occurredAt: (new DateTimeImmutable())->setTimestamp($timestamp),
            detail: implode(' - ', $detailParts),
        );
    }


    /**
     * Convert the base64 public key SendGrid shows into PEM form, for openssl_verify().
     *
     * @param string $name - the provider's name, for the error message.
     *
     * @param string $publicKey - the base64 key, as shown in SendGrid's settings.
     *
     * @return string - the PEM.
     *
     * @throws ConfigurationException - if it isn't a valid public key.
     */
    private static function convertPublicKeyToPem(string $name, string $publicKey) : string
    {
        $base64 = preg_replace('/\s+/', '', $publicKey);
        $pem = "-----BEGIN PUBLIC KEY-----\n" . chunk_split($base64, 64, "\n") . "-----END PUBLIC KEY-----\n";

        if (base64_decode($base64, true) === false || openssl_pkey_get_public($pem) === false)
        {
            $msg = "The webhook_public_key of the SendGrid provider \"{$name}\" isn't a valid public key.";
            throw new ConfigurationException($msg);
        }

        return $pem;
    }
}
