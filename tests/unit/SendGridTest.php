<?php

/*
 * The SendGrid provider: checking its event webhook's signature, and replacing any X-SMTPAPI header the client sent
 * with the service's own.
 */

declare(strict_types = 1);

require_once __DIR__ . '/TestSuite.php';

$suite = new TestSuite('SendGrid');

// A key pair standing in for SendGrid's, which signs the webhook's requests.
$privateKey = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
$publicKeyBase64 = preg_replace('/-----[^-]+-----|\s/', '', openssl_pkey_get_details($privateKey)['key']);
$provider = new SendGridProvider('sendgrid-test', 'SG.fake', $publicKeyBase64);

$event = [
    'email' => 'x@y.org',
    'timestamp' => 1700000000,
    'event' => 'bounce',
    'reason' => '550 no such user',
    'sg_event_id' => 'e1',
    SendGridProvider::MESSAGE_ID_ARGUMENT => 'abc',
];

$body = json_encode([$event]);
$timestamp = '1700000001';
openssl_sign($timestamp . $body, $signature, $privateKey, OPENSSL_ALGO_SHA256);

$request = (new \Slim\Psr7\Factory\ServerRequestFactory())
    ->createServerRequest('POST', '/webhooks/sendgrid-test')
    ->withHeader('X-Twilio-Email-Event-Webhook-Signature', base64_encode($signature))
    ->withHeader('X-Twilio-Email-Event-Webhook-Timestamp', $timestamp);

$events = $provider->parseWebhook($body, $request);
$isParsed = count($events) === 1 && $events->first()->getType() === EmailEventType::BOUNCED;
$suite->check('a correctly signed request is accepted, and the bounce read', $isParsed);
$suite->check('the event is matched to the email', $events->first()->getMessageId() === 'abc');

$parseTampered = function () use ($provider, $body, $request) : void {
    $provider->parseWebhook($body . ' ', $request);
};

$parseUnsigned = function () use ($provider, $body) : void {
    $provider->parseWebhook($body, (new \Slim\Psr7\Factory\ServerRequestFactory())->createServerRequest('POST', '/'));
};

$suite->checkThrows('a tampered body is refused', RequestRejectedException::class, $parseTampered);
$suite->checkThrows('an unsigned request is refused', RequestRejectedException::class, $parseUnsigned);

// The client's X-SMTPAPI header could add recipients, so it's replaced with one that only carries our ID.
$raw = new RawEmail("X-SMTPAPI: {\"to\":[\"evil@x.org\"]}\r\nFrom: a@b.org\r\nSubject: hi\r\n\r\nbody\r\n");
$recipients = new EmailAddressCollection(new EmailAddress('c@d.org'));
$email = new OutgoingEmail('11111111-2222-3333-4444-555555555555', new EmailAddress('a@b.org'), $recipients, $raw);
$prepared = (new ReflectionMethod(SendGridProvider::class, 'prepareForSending'))->invoke($provider, $email)->toString();
$isReplaced = !str_contains($prepared, 'evil') && str_contains($prepared, SendGridProvider::MESSAGE_ID_ARGUMENT);
$suite->check('the client\'s X-SMTPAPI header is replaced with ours', $isReplaced, $prepared);

$suite->finish();
