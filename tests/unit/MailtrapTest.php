<?php

/*
 * The Mailtrap provider: checking its webhook's signature and reading its batched events, replacing the client's
 * X-MT-* headers with the service's own, and its sender checks against a faked API.
 */

declare(strict_types = 1);

require_once __DIR__ . '/TestSuite.php';
require_once __DIR__ . '/Fakes.php';

$suite = new TestSuite('Mailtrap');
$secret = '0123456789abcdef0123456789abcdef';
$provider = new MailtrapProvider('mailtrap', 'smtp-token', $secret);
$messageId = '11111111-2222-3333-4444-555555555555';
$factory = new \Slim\Psr7\Factory\ServerRequestFactory();

/**
 * Create a webhook request, signed with a secret.
 */
$createRequest = function (string $body, string $key) use ($factory) : \Psr\Http\Message\ServerRequestInterface {
    $request = $factory->createServerRequest('POST', '/webhooks/mailtrap');
    return $request->withHeader('Mailtrap-Signature', hash_hmac('sha256', $body, $key));
};

$variables = [MailtrapProvider::MESSAGE_ID_VARIABLE => $messageId];

$body = json_encode([
    'events' => [
        [
            'event' => 'delivery', 'timestamp' => 1728669700, 'message_id' => 'mt-1', 'email' => 'a@x.test',
            'event_id' => 'e1', 'custom_variables' => $variables,
        ],
        [
            'event' => 'soft bounce', 'event_id' => 'e2', 'response' => '451 try later',
            'custom_variables' => $variables,
        ],
    ],
]);

$events = $provider->parseWebhook($body, $createRequest($body, $secret));
$types = [];

foreach ($events as $event)
{
    /* @var $event ProviderEvent */
    $types[] = $event->getType()->value . ':' . $event->getMessageId() . ':' . $event->getProviderEventId();
}

$expected = ["delivered:{$messageId}:e1", "deferred:{$messageId}:e2"];
$suite->check('a signed batch is read, matched by its custom variable', $types === $expected, implode(', ', $types));

$parseWrongKey = function () use ($provider, $body, $createRequest) : void {
    $provider->parseWebhook($body, $createRequest($body, 'other'));
};

$suite->checkThrows('a request signed with another secret is refused', RequestRejectedException::class, $parseWrongKey);

$raw = new RawEmail("X-MT-Custom-Variables: {\"x\":1}\r\nX-MT-Category: c\r\nFrom: a@good.test\r\n\r\nbody\r\n");
$recipients = new EmailAddressCollection(new EmailAddress('c@d.test'));
$email = new OutgoingEmail($messageId, new EmailAddress('a@good.test'), $recipients, $raw);
$prepared = (new ReflectionMethod(MailtrapProvider::class, 'prepareForSending'))->invoke($provider, $email);
$headers = $prepared->getHeaderValues('X-MT-Custom-Variables');
$isReplaced = (count($headers) === 1 && json_decode($headers[0], true) === $variables);
$isReplaced = $isReplaced && $prepared->getHeaderValues('X-MT-Category') === [];
$suite->check('the client\'s X-MT-* headers are replaced with ours', $isReplaced, $prepared->toString());

// --- Sender checks

$dns = new FakeDnsResolver([], []);
$webhookUrl = 'https://smtp.example.org/webhooks/mailtrap';

$domains = [
    [
        'id' => 1, 'domain_name' => 'good.test', 'dns_verified' => true, 'compliance_status' => 'compliant',
        'dns_records' => [['key' => 'inbound_mx', 'type' => 'MX', 'domain' => 'good.test', 'status' => 'missing']],
    ],
    [
        'id' => 2, 'domain_name' => 'new.test', 'dns_verified' => false, 'compliance_status' => 'unverified_dns',
        'dns_records' => [
            [
                'key' => 'dkim1', 'type' => 'CNAME', 'domain' => 'rwmt1._domainkey.new.test',
                'value' => 'rwmt1.dkim.smtp.mailtrap.live', 'status' => 'missing',
            ],
        ],
    ],
];

$hooks = [['url' => $webhookUrl, 'active' => true, 'event_types' => ['delivery', 'soft_bounce', 'bounce']]];

$responses = [
    '/api/domains' => [200, ['data' => $domains]],
    '/api/webhooks' => [200, ['data' => $hooks]],
];

/**
 * Create a checker against a faked API, with some responses replaced.
 */
$createChecker = function (array $replacements) use ($responses, $webhookUrl, $secret) : MailtrapSenderChecker {
    $http = new FakeHttpClient(array_merge($responses, $replacements));
    $factory = new \GuzzleHttp\Psr7\HttpFactory();
    $api = new JsonApiClient('Mailtrap', 'https://mailtrap.io', ['Api-Token' => 't'], $http, $factory);
    return MailtrapSenderChecker::fetch('mailtrap', $api, $secret, $webhookUrl);
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
$expected = 'warning: new.test can\'t send to anyone yet | ok: The webhook is set up | info: Some events aren\'t sent';
$suite->check('an unreviewed domain is flagged, and the webhook passes', $account === $expected, $account);

$good = $describe($checker->checkDomain(DomainDns::fetch($dns, 'good.test')));
$passed = $good === 'ok: good.test is authenticated with Mailtrap';
$suite->check('a verified domain passes, ignoring its inbound MX', $passed, $good);

$newChecks = $checker->checkDomain(DomainDns::fetch($dns, 'new.test'));
$listsRecord = str_contains($newChecks->first()->getDetail(), 'CNAME rwmt1._domainkey.new.test');
$suite->check('an unverified domain lists its missing records', $listsRecord, $newChecks->first()->getDetail());

$oldPath = $createChecker([
    '/api/domains' => [404, ['error' => 'Not found']],
    '/api/accounts' => [200, [['id' => 42, 'name' => 'Me', 'access_levels' => [1000]]]],
    '/api/accounts/42/sending_domains' => [200, ['data' => $domains]],
]);

$old = $describe($oldPath->checkDomain(DomainDns::fetch($dns, 'good.test')));
$passed = $old === 'ok: good.test is authenticated with Mailtrap';
$suite->check('the older account path is used if /api/domains isn\'t found', $passed, $old);

$suite->finish();
