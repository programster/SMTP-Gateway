<?php

/*
 * The Mailgun provider: checking its webhook's signature and reading its events, replacing the client's X-Mailgun-*
 * headers with the service's own, its settings, and its sender checks against a faked API.
 */

declare(strict_types = 1);

use Psr\Http\Message\RequestInterface;

require_once __DIR__ . '/TestSuite.php';
require_once __DIR__ . '/Fakes.php';

$suite = new TestSuite('Mailgun');
$signingKey = 'key-0123456789abcdef';
$provider = new MailgunProvider('mailgun', MailgunRegion::EU, 'postmaster@mg.good.test', 'secret', $signingKey);
$messageId = '11111111-2222-3333-4444-555555555555';

/**
 * Create a webhook request body as Mailgun sends it, signed with a key.
 */
$createBody = function (array $eventData, string $key) : string {
    $timestamp = '1700000000';
    $token = str_repeat('t', 50);
    $signature = hash_hmac('sha256', $timestamp . $token, $key);
    $data = ['signature' => ['timestamp' => $timestamp, 'token' => $token, 'signature' => $signature]];
    return json_encode([...$data, 'event-data' => $eventData]);
};

$request = (new \Slim\Psr7\Factory\ServerRequestFactory())->createServerRequest('POST', '/webhooks/mailgun');

$bounce = [
    'event' => 'failed', 'severity' => 'permanent', 'reason' => 'bounce', 'id' => 'ev1',
    'timestamp' => 1700000000.123, 'recipient' => 'gone@x.test',
    'delivery-status' => ['code' => 550, 'message' => '5.1.1 mailbox unavailable'],
    'user-variables' => [MailgunProvider::MESSAGE_ID_VARIABLE => $messageId],
];

$events = $provider->parseWebhook($createBody($bounce, $signingKey), $request);
$event = $events->first();

$isBounce = (
       count($events) === 1
    && $event->getType() === EmailEventType::BOUNCED
    && $event->getMessageId() === $messageId
    && $event->getRecipient() === 'gone@x.test'
    && $event->getProviderEventId() === 'ev1'
    && str_contains($event->getDetail(), 'mailbox unavailable')
);

$suite->check('a signed permanent failure is read as a bounce for our email', $isBounce, $event->getDetail());

$typeOf = function (array $eventData) use ($provider, $createBody, $signingKey, $request) : string {
    return $provider->parseWebhook($createBody($eventData, $signingKey), $request)->first()->getType()->value;
};

$deferred = $typeOf(['event' => 'failed', 'severity' => 'temporary']);
$suite->check('a temporary failure is a deferral', $deferred === 'deferred', $deferred);
$suppressed = $typeOf(['event' => 'failed', 'severity' => 'permanent', 'reason' => 'suppress-bounce']);
$suite->check('a suppressed address is dropped, not bounced', $suppressed === 'dropped', $suppressed);
$complained = $typeOf(['event' => 'complained']);
$suite->check('a complaint is a spam report', $complained === 'spam_report', $complained);

$parseWrongKey = function () use ($provider, $createBody, $bounce, $request) : void {
    $provider->parseWebhook($createBody($bounce, 'key-wrong'), $request);
};

$parseUnsigned = function () use ($provider, $bounce, $request) : void {
    $provider->parseWebhook(json_encode(['event-data' => $bounce]), $request);
};

$parseWhenOff = function () use ($createBody, $bounce, $request) : void {
    $off = new MailgunProvider('mailgun', MailgunRegion::US, 'postmaster@mg.good.test', 'secret', '');
    $off->parseWebhook($createBody($bounce, ''), $request);
};

$suite->checkThrows('a request signed with another key is refused', RequestRejectedException::class, $parseWrongKey);
$suite->checkThrows('an unsigned request is refused', RequestRejectedException::class, $parseUnsigned);
$suite->checkThrows('nothing is accepted without a signing key', RequestRejectedException::class, $parseWhenOff);

// The client's X-Mailgun-* headers control how Mailgun sends it (e.g. archiving a copy to any URL).
$raw = new RawEmail(
    "X-Mailgun-Archive-To: https://evil.test/\r\nx-mailgun-variables: {\"" . MailgunProvider::MESSAGE_ID_VARIABLE
    . "\":\"other\"}\r\nX-Mailgun-Drop-Message: yes\r\n folded\r\nFrom: a@good.test\r\nSubject: hi\r\n\r\nbody\r\n"
);

$recipients = new EmailAddressCollection(new EmailAddress('c@d.test'));
$email = new OutgoingEmail($messageId, new EmailAddress('a@good.test'), $recipients, $raw);
$prepared = (new ReflectionMethod(MailgunProvider::class, 'prepareForSending'))->invoke($provider, $email);
$headers = $prepared->getHeaderValues('X-Mailgun-Variables');

$isReplaced = (
       !str_contains($prepared->toString(), 'evil')
    && !str_contains($prepared->toString(), 'folded')
    && !str_contains($prepared->toString(), 'Drop-Message')
    && count($headers) === 1
    && json_decode($headers[0], true) === [MailgunProvider::MESSAGE_ID_VARIABLE => $messageId]
    && str_contains($prepared->toString(), "Subject: hi\r\n\r\nbody")
);

$suite->check('the client\'s X-Mailgun-* headers are replaced with ours', $isReplaced, $prepared->toString());

$createWithLogin = function () : void {
    new MailgunProvider('mailgun', MailgunRegion::US, 'postmaster', 'secret', '');
};

$suite->checkThrows('an SMTP login that isn\'t an address is refused', ConfigurationException::class, $createWithLogin);

// --- Settings

$config = ProvidersConfigParser::parse(
    [
        'mode' => 'overflow',
        'providers' => [
            [
                'name' => 'mailgun', 'type' => 'mailgun', 'region' => 'eu',
                'smtp_username' => 'postmaster@mg.good.test', 'smtp_password' => 'mailgun-smtp-password',
                'api_key' => 'key-secret', 'webhook_signing_key' => '',
            ],
        ],
    ],
    new ProviderDefinitionCollection()
);

$yaml = ProvidersConfigParser::toYaml($config, SecretOutput::MASKED);
$isMasked = (str_contains($yaml, 'region: "eu"') && !str_contains($yaml, 'key-secret'));
$suite->check('the settings are read, and the API key is masked', $isMasked, $yaml);

$parseRegionless = function () : void {
    $provider = ['name' => 'm', 'type' => 'mailgun', 'smtp_username' => 'p@mg.x.test', 'smtp_password' => 'x'];
    $data = ['mode' => 'overflow', 'providers' => [$provider]];
    ProvidersConfigParser::parse($data, new ProviderDefinitionCollection());
};

$suite->checkThrows('a missing region is refused', InvalidInputException::class, $parseRegionless);

// --- Sender checks

$dns = new FakeDnsResolver(
    [
        '_dmarc.good.test' => ['v=DMARC1; p=reject'],
        '_dmarc.strict.test' => ['v=DMARC1; p=reject; adkim=s'],
        '_dmarc.other.test' => ['v=DMARC1; p=quarantine'],
    ],
    []
);

$webhookUrl = 'https://smtp.example.org/webhooks/mailgun';

$domain = [
    'domain' => ['name' => 'mg.good.test', 'state' => 'active', 'type' => 'custom', 'is_disabled' => false],
    'sending_dns_records' => [
        ['record_type' => 'TXT', 'name' => 'mg.good.test', 'value' => 'v=spf1', 'valid' => 'valid'],
    ],
];

$hooks = [];

foreach (['delivered', 'permanent_fail', 'temporary_fail', 'complained', 'unsubscribed', 'opened'] as $hook)
{
    $hooks[$hook] = ['urls' => [$webhookUrl]];
}

$responses = [
    '/v4/domains/mg.good.test' => [200, $domain],
    '/v3/domains/mg.good.test/webhooks' => [200, ['webhooks' => $hooks]],
];

/**
 * Create a checker against a faked API.
 */
$createChecker = function (array $replacements, bool $isKeySet) use ($responses, $webhookUrl) : MailgunSenderChecker {
    $http = new FakeHttpClient(array_merge($responses, $replacements));
    $headers = ['Authorization' => 'Basic ' . base64_encode('api:key-test')];
    $factory = new \GuzzleHttp\Psr7\HttpFactory();
    $api = new JsonApiClient('Mailgun', 'https://api.eu.mailgun.net', $headers, $http, $factory);
    return MailgunSenderChecker::fetch('mailgun', 'mg.good.test', $api, $isKeySet, $webhookUrl);
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

$http = new FakeHttpClient($responses);
$headers = ['Authorization' => 'Basic ' . base64_encode('api:key-test')];
$api = new JsonApiClient('Mailgun', 'https://api.eu.mailgun.net', $headers, $http, new \GuzzleHttp\Psr7\HttpFactory());
$checker = MailgunSenderChecker::fetch('mailgun', 'mg.good.test', $api, true, $webhookUrl);
$isReadOnly = true;

foreach ($http->getRequests() as $sent)
{
    /* @var $sent RequestInterface */
    $isAuthorised = ($sent->getHeaderLine('Authorization') === $headers['Authorization']);
    $isReadOnly = $isReadOnly && $sent->getMethod() === 'GET' && $isAuthorised;
}

$suite->check('it only makes GET requests, with the API key', $isReadOnly && count($http->getRequests()) === 2);

$account = $describe($checker->checkAccount());
$expected = 'ok: mg.good.test is verified | ok: The webhooks are set up | info: Some events aren\'t sent';
$suite->check('a verified domain with its webhooks passes, noting missing events', $account === $expected, $account);

$parent = $describe($checker->checkDomain(DomainDns::fetch($dns, 'good.test')));
$passed = $parent === 'ok: Covered by mg.good.test\'s signature';
$suite->check('a parent domain is covered by relaxed alignment', $passed, $parent);

$exact = $describe($checker->checkAddress(new EmailAddress('a@mg.good.test'), DomainDns::fetch($dns, 'mg.good.test')));
$suite->check('the domain itself passes', $exact === 'ok: Signed as mg.good.test', $exact);

$strictResolver = new FakeDnsResolver(['_dmarc.sub.strict.test' => ['v=DMARC1; p=reject; adkim=s']], []);
$strictDns = DomainDns::fetch($strictResolver, 'sub.strict.test');
$strictChecker = MailgunSenderChecker::fetch('mailgun', 'mg.strict.test', null, false, '');
$strict = $describe($strictChecker->checkDomain($strictDns));
$passed = $strict === 'warning: Signed as mg.strict.test, not sub.strict.test';
$suite->check('strict alignment is a warning', $passed, $strict);

$other = $describe($checker->checkDomain(DomainDns::fetch($dns, 'other.test')));
$passed = $other === 'error: Signed as mg.good.test, not other.test';
$suite->check('another organization\'s domain is an error under quarantine', $passed, $other);

$unverified = [
    'domain' => ['name' => 'mg.good.test', 'state' => 'unverified', 'type' => 'custom'],
    'sending_dns_records' => [
        ['record_type' => 'TXT', 'name' => 'mx._domainkey.mg.good.test', 'value' => 'k=rsa', 'valid' => 'unknown'],
    ],
];

$unverifiedChecks = $createChecker(['/v4/domains/mg.good.test' => [200, $unverified]], true)->checkAccount();
$isUnverified = (
       str_contains($describe($unverifiedChecks), 'error: mg.good.test isn\'t verified')
    && str_contains($unverifiedChecks->first()->getDetail(), 'TXT mx._domainkey.mg.good.test')
);

$suite->check('an unverified domain lists its missing records', $isUnverified, $describe($unverifiedChecks));

$notFound = ['/v4/domains/mg.good.test' => [404, ['message' => 'Domain not found']]];
$missing = $describe($createChecker($notFound, true)->checkAccount());
$passed = str_starts_with($missing, 'error: Mailgun has no domain');
$suite->check('a domain that isn\'t in the account is an error', $passed, $missing);

$sandbox = ['domain' => ['name' => 'mg.good.test', 'state' => 'active', 'type' => 'sandbox']];
$sandboxed = $describe($createChecker(['/v4/domains/mg.good.test' => [200, $sandbox]], true)->checkAccount());
$passed = str_starts_with($sandboxed, 'warning: mg.good.test is a sandbox domain');
$suite->check('a sandbox domain is a warning', $passed, $sandboxed);

$noHooks = ['/v3/domains/mg.good.test/webhooks' => [200, ['webhooks' => []]]];
$unhooked = $describe($createChecker($noHooks, true)->checkAccount());
$passed = str_contains($unhooked, 'error: Mailgun isn\'t sending events here');
$suite->check('no webhooks here is an error, with a signing key', $passed, $unhooked);
$untracked = $describe($createChecker($noHooks, false)->checkAccount());
$passed = str_contains($untracked, 'info: Delivery events aren\'t tracked');
$suite->check('and only a note, without one', $passed, $untracked);
$refused = $describe($createChecker([], false)->checkAccount());
$passed = str_contains($refused, 'error: Its events are all refused');
$suite->check('webhooks posting here without a signing key are an error', $passed, $refused);

$keyless = MailgunSenderChecker::fetch('mailgun', 'mg.good.test', null, false, $webhookUrl);
$keylessAccount = $describe($keyless->checkAccount());
$passed = str_starts_with($keylessAccount, 'info: The domain can\'t be checked');
$suite->check('without an API key, the domain is only noted', $passed, $keylessAccount);

try
{
    $createChecker(['/v4/domains/mg.good.test' => [401, ['message' => 'Invalid private key']]], true);
    $rejected = 'no exception';
}
catch (ProviderLookupException $e)
{
    $rejected = $e->getMessage();
}

$passed = str_contains($rejected, 'Mailgun rejected the API key');
$suite->check('a rejected API key can\'t be checked at all', $passed, $rejected);

$suite->finish();
