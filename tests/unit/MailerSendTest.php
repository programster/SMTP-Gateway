<?php

/*
 * The MailerSend provider: checking its webhook's signature and reading its events, replacing the client's
 * X-MailerSend-* headers with a tag carrying the service's ID, and its sender checks against a faked API.
 */

declare(strict_types = 1);

require_once __DIR__ . '/TestSuite.php';
require_once __DIR__ . '/Fakes.php';

$suite = new TestSuite('MailerSend');
$secret = 'signing-secret-123';
$provider = new MailerSendProvider('mailersend', 'MS_abc@good.test', 'mssp.password', $secret);
$messageId = '11111111-2222-3333-4444-555555555555';
$factory = new \Slim\Psr7\Factory\ServerRequestFactory();

/**
 * Create a webhook request, signed with a secret.
 */
$createRequest = function (string $body, string $key) use ($factory) : \Psr\Http\Message\ServerRequestInterface {
    $request = $factory->createServerRequest('POST', '/webhooks/mailersend');
    return $request->withHeader('Signature', hash_hmac('sha256', $body, $key));
};

$body = json_encode([
    'type' => 'activity.hard_bounced',
    'created_at' => '2025-08-05T21:24:02.000000Z',
    'data' => [
        'id' => '6892766a5b66e2daf3dc9158', 'type' => 'hard_bounced', 'recipient' => 'gone@x.test',
        'tags' => ['newsletter', $messageId], 'meta' => ['bounce_reason' => 'Host or domain name not found'],
    ],
]);

$event = $provider->parseWebhook($body, $createRequest($body, $secret))->first();

$isBounce = (
       $event->getType() === EmailEventType::BOUNCED
    && $event->getMessageId() === $messageId
    && $event->getProviderEventId() === '6892766a5b66e2daf3dc9158'
    && $event->getOccurredAt()->format('Y-m-d H:i') === '2025-08-05 21:24'
    && $event->getDetail() === 'Host or domain name not found'
);

$suite->check('a signed hard bounce is read, matched by its UUID tag', $isBounce, $event->getDetail());

$testBody = json_encode(['type' => 'webhook.test', 'data' => []]);
$isTestIgnored = (count($provider->parseWebhook($testBody, $createRequest($testBody, $secret))) === 0);
$suite->check('a signed test request has no events', $isTestIgnored);

$parseWrongKey = function () use ($provider, $body, $createRequest) : void {
    $provider->parseWebhook($body, $createRequest($body, 'other-secret'));
};

$parseTampered = function () use ($provider, $body, $createRequest, $secret) : void {
    $provider->parseWebhook($body . ' ', $createRequest($body, $secret));
};

$suite->checkThrows('a request signed with another secret is refused', RequestRejectedException::class, $parseWrongKey);
$suite->checkThrows('a tampered body is refused', RequestRejectedException::class, $parseTampered);

$raw = new RawEmail("X-MailerSend-Tags: " . str_repeat('a', 36) . "\r\nFrom: a@good.test\r\n\r\nbody\r\n");
$recipients = new EmailAddressCollection(new EmailAddress('c@d.test'));
$email = new OutgoingEmail($messageId, new EmailAddress('a@good.test'), $recipients, $raw);
$prepared = (new ReflectionMethod(MailerSendProvider::class, 'prepareForSending'))->invoke($provider, $email);
$isReplaced = ($prepared->getHeaderValues('X-MailerSend-Tags') === [$messageId]);
$suite->check('the client\'s tags are replaced with our ID', $isReplaced, $prepared->toString());

// --- Sender checks

$dns = new FakeDnsResolver([], []);
$webhookUrl = 'https://smtp.example.org/webhooks/mailersend';
$events = ['activity.delivered', 'activity.soft_bounced', 'activity.hard_bounced'];

$responses = [
    '/v1/domains' => [
        200,
        [
            'data' => [
                ['id' => 'd1', 'name' => 'good.test', 'is_verified' => true, 'dkim' => true, 'spf' => true],
                ['id' => 'd2', 'name' => 'new.test', 'is_verified' => false, 'dkim' => false, 'spf' => true],
            ],
        ],
    ],
    '/v1/webhooks' => [
        200,
        ['data' => [['url' => $webhookUrl, 'events' => $events, 'enabled' => true, 'secret' => $secret]]],
    ],
];

/**
 * Create a checker against a faked API, with some responses replaced.
 */
$createChecker = function (array $replacements, string $key) use ($responses, $webhookUrl) : MailerSendSenderChecker {
    $http = new FakeHttpClient(array_merge($responses, $replacements));
    $factory = new \GuzzleHttp\Psr7\HttpFactory();
    $headers = ['Authorization' => 'Bearer t'];
    $api = new JsonApiClient('MailerSend', 'https://api.mailersend.com', $headers, $http, $factory);
    return MailerSendSenderChecker::fetch('mailersend', $api, $key, $webhookUrl);
};

/**
 * Get the checks as "level: title" lines, for comparing.
 */
$describe = function (SenderCheckCollection $checks) : string {
    $lines = [];

    foreach ($checks as $check)
    {
        /* @var $check SenderCheck */
        $lines[] = $check->getLevel()->value . ': ' . $check->getTitle();
    }

    return implode(' | ', $lines);
};

$checker = $createChecker([], $secret);
$account = $describe($checker->checkAccount());
$passed = $account === 'ok: The webhook is set up | info: Some events aren\'t sent';
$suite->check('a set-up webhook passes', $passed, $account);

$good = $describe($checker->checkDomain(DomainDns::fetch($dns, 'good.test')));
$suite->check('a verified domain passes', $good === 'ok: good.test is authenticated with MailerSend', $good);

$newChecks = $checker->checkAddress(new EmailAddress('a@new.test'), DomainDns::fetch($dns, 'new.test'));
$isUnfinished = str_contains($newChecks->first()->getDetail(), 'the DKIM CNAME records');
$suite->check('an unverified domain says what\'s missing', $isUnfinished, $newChecks->first()->getDetail());

$other = $describe($checker->checkAddress(new EmailAddress('a@other.test'), DomainDns::fetch($dns, 'other.test')));
$suite->check('another domain is an error', $other === 'error: MailerSend hasn\'t verified a@other.test', $other);

$wrong = $describe($createChecker([], 'another-secret')->checkAccount());
$passed = str_contains($wrong, 'error: The webhook\'s signing secret is wrong');
$suite->check('a different signing secret is an error', $passed, $wrong);

$forbidden = $describe($createChecker(['/v1/webhooks' => [403, ['message' => 'Forbidden']]], $secret)->checkAccount());
$passed = str_contains($forbidden, 'info: Can\'t check the webhook');
$suite->check('a token that can\'t read webhooks only notes it', $passed, $forbidden);

$suite->finish();
