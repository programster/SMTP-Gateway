<?php

/*
 * The Postmark provider: checking its webhook's Basic auth and reading its events, replacing the client's X-PM-*
 * headers with the service's own, its settings, and its sender checks against a faked API.
 */

declare(strict_types = 1);

require_once __DIR__ . '/TestSuite.php';
require_once __DIR__ . '/Fakes.php';

$suite = new TestSuite('Postmark');
$secret = str_repeat('s3cr3t', 5);
$provider = new PostmarkProvider('postmark', 'server-token', 'outbound', $secret);
$messageId = '11111111-2222-3333-4444-555555555555';
$factory = new \Slim\Psr7\Factory\ServerRequestFactory();

/**
 * Create a webhook request with a Basic auth password.
 */
$createRequest = function (string $password) use ($factory) : \Psr\Http\Message\ServerRequestInterface {
    $request = $factory->createServerRequest('POST', '/webhooks/postmark');
    return $request->withHeader('Authorization', 'Basic ' . base64_encode("webhook:{$password}"));
};

$bounce = [
    'RecordType' => 'Bounce', 'ID' => 692560173, 'Type' => 'HardBounce', 'MessageID' => 'pm-1',
    'Metadata' => [PostmarkProvider::MESSAGE_ID_METADATA => $messageId], 'Email' => 'gone@x.test',
    'Description' => 'The server was unable to deliver your message', 'BouncedAt' => '2026-11-05T16:33:54.9070259Z',
];

$event = $provider->parseWebhook(json_encode($bounce), $createRequest($secret))->first();

$isBounce = (
       $event->getType() === EmailEventType::BOUNCED
    && $event->getMessageId() === $messageId
    && $event->getRecipient() === 'gone@x.test'
    && $event->getProviderEventId() === 'Bounce:692560173'
    && $event->getOccurredAt()->format('Y-m-d H:i:s') === '2026-11-05 16:33:54'
);

$suite->check('an authorised hard bounce is read, with its 7-digit fraction time', $isBounce, $event->getDetail());

$typeOf = function (array $data) use ($provider, $createRequest, $secret) : string {
    return $provider->parseWebhook(json_encode($data), $createRequest($secret))->first()->getType()->value;
};

$transient = $typeOf(['RecordType' => 'Bounce', 'Type' => 'Transient']);
$suite->check('a transient bounce is a deferral', $transient === 'deferred', $transient);
$delivered = $typeOf(['RecordType' => 'Delivery', 'Recipient' => 'a@x.test', 'DeliveredAt' => '2026-01-01T00:00:00Z']);
$suite->check('a delivery is read', $delivered === 'delivered', $delivered);
$unsubscribed = $typeOf(['RecordType' => 'SubscriptionChange', 'SuppressSending' => true]);
$suite->check('a suppression is an unsubscribe', $unsubscribed === 'unsubscribed', $unsubscribed);

$parseWrongPassword = function () use ($provider, $bounce, $createRequest) : void {
    $provider->parseWebhook(json_encode($bounce), $createRequest('wrong'));
};

$parseUnauthenticated = function () use ($provider, $bounce, $factory) : void {
    $provider->parseWebhook(json_encode($bounce), $factory->createServerRequest('POST', '/'));
};

$parseWhenOff = function () use ($bounce, $createRequest) : void {
    $off = new PostmarkProvider('postmark', 'server-token', 'outbound', '');
    $off->parseWebhook(json_encode($bounce), $createRequest(''));
};

$suite->checkThrows('a wrong password is refused', RequestRejectedException::class, $parseWrongPassword);
$suite->checkThrows('no password is refused', RequestRejectedException::class, $parseUnauthenticated);
$suite->checkThrows('nothing is accepted without a secret', RequestRejectedException::class, $parseWhenOff);

$createWithShortSecret = function () : void {
    new PostmarkProvider('postmark', 'server-token', 'outbound', 'short');
};

$suite->checkThrows('a short secret is refused', ConfigurationException::class, $createWithShortSecret);

$raw = new RawEmail(
    "X-PM-Message-Stream: broadcast\r\nX-PM-Metadata-" . PostmarkProvider::MESSAGE_ID_METADATA . ": other\r\n"
    . "X-PM-KeepID: true\r\nFrom: a@good.test\r\nSubject: hi\r\n\r\nbody\r\n"
);

$recipients = new EmailAddressCollection(new EmailAddress('c@d.test'));
$email = new OutgoingEmail($messageId, new EmailAddress('a@good.test'), $recipients, $raw);
$prepared = (new ReflectionMethod(PostmarkProvider::class, 'prepareForSending'))->invoke($provider, $email);

$isReplaced = (
       $prepared->getHeaderValues('X-PM-Message-Stream') === ['outbound']
    && $prepared->getHeaderValues('X-PM-Metadata-' . PostmarkProvider::MESSAGE_ID_METADATA) === [$messageId]
    && $prepared->getHeaderValues('X-PM-KeepID') === []
);

$suite->check('the client\'s X-PM-* headers are replaced with ours', $isReplaced, $prepared->toString());

// --- Sender checks

$dns = new FakeDnsResolver(['_dmarc.single.test' => ['v=DMARC1; p=none']], []);
$webhookUrl = 'https://smtp.example.org/webhooks/postmark';

$webhook = [
    'ID' => 1, 'Url' => $webhookUrl, 'MessageStream' => 'outbound',
    'HttpAuth' => ['Username' => 'webhook', 'Password' => $secret],
    'Triggers' => [
        'Delivery' => ['Enabled' => true], 'Bounce' => ['Enabled' => true], 'SpamComplaint' => ['Enabled' => true],
        'Open' => ['Enabled' => false], 'Click' => ['Enabled' => false], 'SubscriptionChange' => ['Enabled' => true],
    ],
];

$responses = [
    '/server' => [200, ['ID' => 1, 'SmtpApiActivated' => true, 'DeliveryType' => 'Live']],
    '/webhooks' => [200, ['Webhooks' => [$webhook]]],
    '/domains' => [
        200,
        [
            'TotalCount' => 2,
            'Domains' => [
                ['Name' => 'good.test', 'DKIMVerified' => true, 'ReturnPathDomainVerified' => true, 'ID' => 1],
                ['Name' => 'half.test', 'DKIMVerified' => true, 'ReturnPathDomainVerified' => false, 'ID' => 2],
                ['Name' => 'new.test', 'DKIMVerified' => false, 'ReturnPathDomainVerified' => false, 'ID' => 3],
            ],
        ],
    ],
    '/senders' => [
        200,
        ['SenderSignatures' => [['EmailAddress' => 'me@single.test', 'Confirmed' => true, 'ID' => 4]]],
    ],
];

/**
 * Create a checker against a faked API, with some responses replaced.
 */
$createChecker = function (
    array $replacements,
    bool $hasAccountToken,
    string $key
) use ($responses, $webhookUrl) : PostmarkSenderChecker {
    $http = new FakeHttpClient(array_merge($responses, $replacements));
    $factory = new \GuzzleHttp\Psr7\HttpFactory();
    $url = 'https://api.postmarkapp.com';
    $server = new JsonApiClient('Postmark', $url, ['X-Postmark-Server-Token' => 's'], $http, $factory);
    $account = new JsonApiClient('Postmark', $url, ['X-Postmark-Account-Token' => 'a'], $http, $factory);
    $accountApi = $hasAccountToken ? $account : null;
    return PostmarkSenderChecker::fetch('postmark', $server, $accountApi, 'outbound', $key, $webhookUrl);
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

$checker = $createChecker([], true, $secret);
$account = $describe($checker->checkAccount());

$expected =
    'ok: The server accepts email over SMTP | ok: The webhook is set up | info: Some events aren\'t sent';

$suite->check('a set-up server passes, noting missing events', $account === $expected, $account);

$good = $describe($checker->checkDomain(DomainDns::fetch($dns, 'good.test')));
$suite->check('a DKIM-verified domain passes', $good === 'ok: good.test is authenticated with Postmark', $good);

$half = $describe($checker->checkDomain(DomainDns::fetch($dns, 'half.test')));
$expected = 'ok: half.test is authenticated with Postmark | info: half.test\'s Return-Path isn\'t verified';
$suite->check('an unverified Return-Path is noted', $half === $expected, $half);

$new = $describe($checker->checkDomain(DomainDns::fetch($dns, 'new.test')));
$suite->check('an unverified domain is an error', $new === 'error: new.test\'s authentication isn\'t finished', $new);

$single = $describe($checker->checkAddress(new EmailAddress('me@single.test'), DomainDns::fetch($dns, 'single.test')));
$suite->check('a single sender signature is a warning', $single === 'warning: Only the address is verified', $single);

$other = $describe($checker->checkAddress(new EmailAddress('x@other.test'), DomainDns::fetch($dns, 'other.test')));
$suite->check('an unknown sender is an error', $other === 'error: Postmark hasn\'t verified x@other.test', $other);

$wrongPassword = $describe($createChecker([], true, str_repeat('x', 30))->checkAccount());
$isWrong = str_contains($wrongPassword, 'error: The webhook\'s password is wrong');
$suite->check('a webhook with another password is an error', $isWrong, $wrongPassword);

$credentialsInUrl = $webhook;
$credentialsInUrl['HttpAuth'] = null;
$credentialsInUrl['Url'] = createWebhookUrlWithSecret($webhookUrl, $secret);
$inUrlHooks = ['/webhooks' => [200, ['Webhooks' => [$credentialsInUrl]]]];
$inUrl = $describe($createChecker($inUrlHooks, true, $secret)->checkAccount());
$passed = str_contains($inUrl, 'ok: The webhook is set up');
$suite->check('a password in the webhook\'s address is read', $passed, $inUrl);

$offServer = ['/server' => [200, ['SmtpApiActivated' => false, 'DeliveryType' => 'Sandbox']]];
$off = $describe($createChecker($offServer, true, $secret)->checkAccount());

$isOff = (
       str_contains($off, 'error: SMTP is switched off for the server')
    && str_contains($off, 'warning: The server is in sandbox mode')
);

$suite->check('SMTP being off, and sandbox mode, are flagged', $isOff, $off);

$noAccount = $createChecker([], false, $secret);
$noAccountChecks = $describe($noAccount->checkAccount());
$passed = str_contains($noAccountChecks, 'info: The senders can\'t be checked');
$suite->check('without an account token, the senders are noted', $passed, $noAccountChecks);
$unchecked = $describe($noAccount->checkDomain(DomainDns::fetch($dns, 'good.test')));
$suite->check('and not claimed to be unverified', $unchecked === 'warning: Can\'t check good.test', $unchecked);

$noHooks = $describe($createChecker(['/webhooks' => [200, ['Webhooks' => []]]], true, '')->checkAccount());
$passed = str_contains($noHooks, 'info: Delivery events aren\'t tracked');
$suite->check('no webhook and no secret is a note', $passed, $noHooks);

$suite->finish();
