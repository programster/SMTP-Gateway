<?php

/*
 * The Mailjet provider: checking its event callbacks' secret and reading their events, replacing the client's
 * X-MJ-* and X-Mailjet-* headers with the service's own, and its sender checks against a faked API.
 */

declare(strict_types = 1);

require_once __DIR__ . '/TestSuite.php';
require_once __DIR__ . '/Fakes.php';

$suite = new TestSuite('Mailjet');
$secret = str_repeat('s3cr3t', 5);
$provider = new MailjetProvider('mailjet', 'public-key', 'secret-key', $secret);
$messageId = '11111111-2222-3333-4444-555555555555';
$factory = new \Slim\Psr7\Factory\ServerRequestFactory();
$authorization = 'Basic ' . base64_encode("webhook:{$secret}");
$request = $factory->createServerRequest('POST', '/')->withHeader('Authorization', $authorization);

$bounce = [
    'event' => 'bounce', 'time' => 1430812195, 'MessageID' => 13792286917004336, 'email' => 'gone@x.test',
    'CustomID' => $messageId, 'hard_bounce' => true, 'error_related_to' => 'recipient', 'error' => 'user unknown',
];

$event = $provider->parseWebhook(json_encode($bounce), $request)->first();

$isBounce = (
       $event->getType() === EmailEventType::BOUNCED
    && $event->getMessageId() === $messageId
    && $event->getProviderEventId() === "bounce:13792286917004336:gone@x.test:1430812195"
    && $event->getDetail() === 'recipient - user unknown'
);

$suite->check('a hard bounce with the secret is read', $isBounce, $event->getProviderEventId());

$grouped = [['event' => 'sent', 'CustomID' => $messageId], ['event' => 'bounce', 'hard_bounce' => false]];
$types = [];

foreach ($provider->parseWebhook(json_encode($grouped), $request) as $groupedEvent)
{
    /* @var $groupedEvent ProviderEvent */
    $types[] = $groupedEvent->getType()->value;
}

$isGrouped = ($types === ['delivered', 'deferred']);
$suite->check('grouped events are read, a soft bounce as a deferral', $isGrouped, implode(',', $types));

$parseUnauthenticated = function () use ($provider, $bounce, $factory) : void {
    $provider->parseWebhook(json_encode($bounce), $factory->createServerRequest('POST', '/'));
};

$suite->checkThrows('a callback without the secret is refused', RequestRejectedException::class, $parseUnauthenticated);

$raw = new RawEmail(
    "X-MJ-TemplateErrorReporting: spy@evil.test\r\nX-MJ-CustomID: other\r\nX-Mailjet-TrackOpen: 0\r\n"
    . "From: a@good.test\r\n\r\nbody\r\n"
);

$recipients = new EmailAddressCollection(new EmailAddress('c@d.test'));
$email = new OutgoingEmail($messageId, new EmailAddress('a@good.test'), $recipients, $raw);
$prepared = (new ReflectionMethod(MailjetProvider::class, 'prepareForSending'))->invoke($provider, $email);

$isReplaced = (
       $prepared->getHeaderValues('X-MJ-CustomID') === [$messageId]
    && !str_contains($prepared->toString(), 'evil')
    && $prepared->getHeaderValues('X-Mailjet-TrackOpen') === []
);

$name = 'the client\'s X-MJ-* and X-Mailjet-* headers are replaced with ours';
$suite->check($name, $isReplaced, $prepared->toString());

// --- Sender checks

$dns = new FakeDnsResolver([], []);
$webhookUrl = 'https://smtp.example.org/webhooks/mailjet';
$callbackUrl = createWebhookUrlWithSecret($webhookUrl, $secret);

$responses = [
    '/v3/REST/dns' => [
        200,
        [
            'Count' => 2,
            'Data' => [
                ['Domain' => 'good.test', 'DKIMStatus' => 'OK', 'SPFStatus' => 'OK'],
                [
                    'Domain' => 'new.test', 'DKIMStatus' => 'Not checked', 'SPFStatus' => 'Not found',
                    'DKIMRecordName' => 'mailjet._domainkey.new.test.', 'DKIMRecordValue' => 'k=rsa; p=MIGf',
                ],
            ],
        ],
    ],
    '/v3/REST/sender' => [
        200,
        [
            'Data' => [
                ['Email' => '*@good.test', 'Status' => 'Active'],
                ['Email' => 'me@single.test', 'Status' => 'Active'],
                ['Email' => 'pending@single.test', 'Status' => 'Inactive'],
            ],
        ],
    ],
    '/v3/REST/eventcallbackurl' => [
        200,
        [
            'Data' => [
                ['Url' => $callbackUrl, 'EventType' => 'sent', 'Status' => 'alive', 'IsBackup' => false],
                ['Url' => $callbackUrl, 'EventType' => 'bounce', 'Status' => 'alive', 'IsBackup' => false],
                ['Url' => $callbackUrl, 'EventType' => 'blocked', 'Status' => 'alive', 'IsBackup' => false],
                ['Url' => $callbackUrl, 'EventType' => 'spam', 'Status' => 'dead', 'IsBackup' => false],
            ],
        ],
    ],
];

/**
 * Create a checker against a faked API, with some responses replaced.
 */
$createChecker = function (array $replacements) use ($responses, $webhookUrl, $secret) : MailjetSenderChecker {
    $http = new FakeHttpClient(array_merge($responses, $replacements));
    $factory = new \GuzzleHttp\Psr7\HttpFactory();
    $headers = ['Authorization' => 'Basic ' . base64_encode('public-key:secret-key')];
    $api = new JsonApiClient('Mailjet', 'https://api.mailjet.com', $headers, $http, $factory);
    return MailjetSenderChecker::fetch('mailjet', $api, $secret, $webhookUrl);
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

$checker = $createChecker([]);
$account = $describe($checker->checkAccount());
$expected = 'ok: The webhook is set up | info: Some events aren\'t sent';
$passed = $account === $expected;
$suite->check('callbacks for each event type are grouped, and a dead one counts as missing', $passed, $account);

$good = $describe($checker->checkDomain(DomainDns::fetch($dns, 'good.test')));
$suite->check('a domain with its DKIM found passes', $good === 'ok: good.test is authenticated with Mailjet', $good);

$newChecks = $checker->checkDomain(DomainDns::fetch($dns, 'new.test'));
$listsRecord = str_contains($newChecks->first()->getDetail(), 'TXT mailjet._domainkey.new.test → k=rsa');
$suite->check('a domain without its DKIM lists the record', $listsRecord, $newChecks->first()->getDetail());

$pendingChecks = $checker->checkAddress(new EmailAddress('pending@single.test'), DomainDns::fetch($dns, 'single.test'));
$pending = $describe($pendingChecks);
$passed = $pending === 'error: pending@single.test isn\'t verified yet';
$suite->check('an inactive sender is an error', $passed, $pending);

$missing = $describe($createChecker(['/v3/REST/eventcallbackurl' => [200, ['Data' => []]]])->checkAccount());
$passed = str_starts_with($missing, 'error: Mailjet isn\'t sending events here');
$suite->check('no callbacks here is an error', $passed, $missing);

$suite->finish();
