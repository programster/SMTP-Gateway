<?php

/*
 * The Brevo provider: checking its webhook's secret and reading its events, replacing the client's X-Mailin-* and
 * X-Sib-* headers with the service's own, and its sender checks against a faked API.
 */

declare(strict_types = 1);

require_once __DIR__ . '/TestSuite.php';
require_once __DIR__ . '/Fakes.php';

$suite = new TestSuite('Brevo');
$secret = str_repeat('s3cr3t', 5);
$provider = new BrevoProvider('brevo', '8a1b2c001@smtp-brevo.com', 'xsmtpsib-key', $secret);
$messageId = '11111111-2222-3333-4444-555555555555';
$factory = new \Slim\Psr7\Factory\ServerRequestFactory();
$basicHeader = 'Basic ' . base64_encode("webhook:{$secret}");
$basic = $factory->createServerRequest('POST', '/')->withHeader('Authorization', $basicHeader);
$bearer = $factory->createServerRequest('POST', '/')->withHeader('Authorization', "Bearer {$secret}");

$bounce = [
    'event' => 'hard_bounce', 'email' => 'gone@x.test', 'id' => 26224, 'message-id' => '<1@smtp-relay.mailin.fr>',
    'ts_event' => 1604933654, 'reason' => 'unknown user', 'X-Mailin-custom' => $messageId,
];

$event = $provider->parseWebhook(json_encode($bounce), $basic)->first();

$isBounce = (
       $event->getType() === EmailEventType::BOUNCED
    && $event->getMessageId() === $messageId
    && $event->getRecipient() === 'gone@x.test'
    && $event->getOccurredAt()->getTimestamp() === 1604933654
    && $event->getDetail() === 'unknown user'
);

$suite->check('a hard bounce with the secret is read', $isBounce, $event->getDetail());

$batch = [['event' => 'delivered', 'X-Mailin-custom' => $messageId], ['event' => 'soft_bounce']];
$events = $provider->parseWebhook(json_encode($batch), $bearer);
$types = [];

foreach ($events as $batchEvent)
{
    /* @var $batchEvent ProviderEvent */
    $types[] = $batchEvent->getType()->value;
}

$isBatchRead = ($types === ['delivered', 'deferred']);
$suite->check('a batch with the secret as a bearer token is read', $isBatchRead, implode(',', $types));

$parseWrong = function () use ($provider, $bounce, $factory) : void {
    $request = $factory->createServerRequest('POST', '/')->withHeader('Authorization', 'Bearer wrong');
    $provider->parseWebhook(json_encode($bounce), $request);
};

$suite->checkThrows('a wrong token is refused', RequestRejectedException::class, $parseWrong);

$raw = new RawEmail(
    "X-Sib-Sandbox: drop\r\nX-Mailin-custom: other\r\nX-Mailin-Tag: t\r\nFrom: a@good.test\r\n\r\nbody\r\n"
);

$recipients = new EmailAddressCollection(new EmailAddress('c@d.test'));
$email = new OutgoingEmail($messageId, new EmailAddress('a@good.test'), $recipients, $raw);
$prepared = (new ReflectionMethod(BrevoProvider::class, 'prepareForSending'))->invoke($provider, $email);

$isReplaced = (
       $prepared->getHeaderValues('X-Mailin-custom') === [$messageId]
    && $prepared->getHeaderValues('X-Sib-Sandbox') === []
    && $prepared->getHeaderValues('X-Mailin-Tag') === []
);

$name = 'the client\'s X-Mailin-* and X-Sib-* headers are replaced with ours';
$suite->check($name, $isReplaced, $prepared->toString());

// --- Sender checks

$dns = new FakeDnsResolver([], []);
$webhookUrl = 'https://smtp.example.org/webhooks/brevo';

$responses = [
    '/v3/account' => [200, ['relay' => ['enabled' => true, 'data' => ['relay' => 'smtp-relay.brevo.com']]]],
    '/v3/senders/domains' => [
        200,
        [
            'domains' => [
                ['domain_name' => 'good.test', 'authenticated' => true, 'verified' => true],
                ['domain_name' => 'new.test', 'authenticated' => false, 'verified' => true],
            ],
        ],
    ],
    '/v3/senders/domains/new.test' => [
        200,
        [
            'domain' => 'new.test',
            'dns_records' => [
                'dkim_record' => [
                    'host_name' => 'mail._domainkey', 'type' => 'TXT', 'value' => 'k=rsa', 'status' => false,
                ],
                'brevo_code' => ['host_name' => '@', 'type' => 'TXT', 'value' => 'brevo-code=1', 'status' => true],
            ],
        ],
    ],
    '/v3/senders' => [200, ['senders' => [['email' => 'me@single.test', 'active' => true]]]],
    '/v3/webhooks' => [
        200,
        [
            'webhooks' => [
                [
                    'url' => WebhookBasicAuth::createUrl($webhookUrl, $secret), 'type' => 'transactional',
                    'events' => ['delivered', 'hardBounce', 'softBounce'],
                ],
            ],
        ],
    ],
];

/**
 * Create a checker against a faked API, with some responses replaced.
 */
$createChecker = function (array $replacements, string $key) use ($responses, $webhookUrl) : BrevoSenderChecker {
    $http = new FakeHttpClient(array_merge($responses, $replacements));
    $factory = new \GuzzleHttp\Psr7\HttpFactory();
    $api = new JsonApiClient('Brevo', 'https://api.brevo.com', ['api-key' => 'k'], $http, $factory);
    return BrevoSenderChecker::fetch('brevo', $api, $key, $webhookUrl);
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

$expected =
    'ok: The account\'s SMTP relay is switched on | ok: The webhook is set up | info: Some events aren\'t sent';

$suite->check('a set-up account passes, noting missing events', $account === $expected, $account);

$good = $describe($checker->checkDomain(DomainDns::fetch($dns, 'good.test')));
$suite->check('an authenticated domain passes', $good === 'ok: good.test is authenticated with Brevo', $good);

$newChecks = $checker->checkDomain(DomainDns::fetch($dns, 'new.test'));
$new = $describe($newChecks);
$listsRecord = str_contains($newChecks->first()->getDetail(), 'TXT mail._domainkey.new.test → k=rsa');
$isListed = ($listsRecord && str_starts_with($new, 'error:'));
$suite->check('an unauthenticated domain lists its missing records', $isListed, $newChecks->first()->getDetail());

$single = $describe($checker->checkAddress(new EmailAddress('me@single.test'), DomainDns::fetch($dns, 'single.test')));
$suite->check('a single sender is a warning', $single === 'warning: Only the address is verified', $single);

$off = $describe($createChecker(['/v3/account' => [200, ['relay' => ['enabled' => false]]]], $secret)->checkAccount());
$suite->check('a switched-off relay is an error', str_starts_with($off, 'error: The SMTP relay is switched off'), $off);

$wrong = $describe($createChecker([], str_repeat('x', 30))->checkAccount());
$passed = str_contains($wrong, 'error: The webhook\'s password is wrong');
$suite->check('a webhook with another secret is an error', $passed, $wrong);

$keyless = BrevoSenderChecker::fetch('brevo', null, '', $webhookUrl);
$keylessAccount = $describe($keyless->checkAccount());
$passed = str_starts_with($keylessAccount, 'info: The senders can\'t be checked');
$suite->check('without an API key, the senders are only noted', $passed, $keylessAccount);

$suite->finish();
