<?php

/*
 * The SMTP2GO provider: removing the client's X-Smtp2go-* headers, and its sender checks against a faked API, which
 * must only ever POST to its view-only endpoints.
 */

declare(strict_types = 1);

require_once __DIR__ . '/TestSuite.php';
require_once __DIR__ . '/Fakes.php';

$suite = new TestSuite('SMTP2GO');
$provider = new Smtp2GoProvider('smtp2go', 'relay-user', 'relay-password');
$messageId = '11111111-2222-3333-4444-555555555555';

$createWithoutPassword = function () : void {
    new Smtp2GoProvider('smtp2go', 'relay-user', ' ');
};

$suite->checkThrows('a provider without a password is refused', ConfigurationException::class, $createWithoutPassword);
$suite->check('it has no webhook yet', ProviderType::SMTP2GO->hasWebhook() === false);

$raw = new RawEmail("X-Smtp2go-Test: 1\r\nx-smtp2go-other: 2\r\nX-Keep: 3\r\nFrom: a@good.test\r\n\r\nbody\r\n");
$recipients = new EmailAddressCollection(new EmailAddress('c@d.test'));
$email = new OutgoingEmail($messageId, new EmailAddress('a@good.test'), $recipients, $raw);
$prepared = (new ReflectionMethod(Smtp2GoProvider::class, 'prepareForSending'))->invoke($provider, $email);

$isStripped = (
       $prepared->getHeaderValues('X-Smtp2go-Test') === []
    && $prepared->getHeaderValues('X-Smtp2go-Other') === []
    && $prepared->getHeaderValues('X-Keep') === ['3']
);

$suite->check('the client\'s X-Smtp2go-* headers are removed, and others kept', $isStripped, $prepared->toString());

// --- Sender checks

$dns = new FakeDnsResolver([], []);

$domains = [
    [
        'domain' => [
            'fulldomain' => 'good.test', 'dkim_selector' => 's1', 'dkim_verified' => true,
            'dkim_value' => 'dkim.smtp2go.net', 'rpath_selector' => 'em1', 'rpath_verified' => true,
            'rpath_value' => 'return.smtp2go.net',
        ],
    ],
    [
        'domain' => [
            'fulldomain' => 'new.test', 'dkim_selector' => 's2', 'dkim_verified' => true,
            'dkim_value' => 'dkim.smtp2go.net', 'rpath_selector' => 'em2', 'rpath_verified' => false,
            'rpath_value' => 'return.smtp2go.net',
        ],
    ],
];

$senders = [['email_address' => 'me@single.test', 'verified' => true]];

$responses = [
    '/v3/domain/view' => [200, ['request_id' => 'r1', 'data' => ['domains' => $domains]]],
    '/v3/single_sender_emails/view' => [200, ['data' => ['request_id' => 'r2', 'senders' => $senders]]],
];

/**
 * Create a checker against a faked API, with some responses replaced, and get it with the HTTP client.
 */
$createChecker = function (array $replacements) use ($responses) : array {
    $http = new FakeHttpClient(array_merge($responses, $replacements));
    $factory = new \GuzzleHttp\Psr7\HttpFactory();
    $headers = ['X-Smtp2go-Api-Key' => 'api-test'];
    $api = new JsonApiClient('SMTP2GO', 'https://api.smtp2go.com/v3', $headers, $http, $factory);
    return [Smtp2GoSenderChecker::fetch('smtp2go', $api), $http];
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

[$checker, $http] = $createChecker([]);
$requests = [];

foreach ($http->getRequests() as $request)
{
    $requests[] = $request->getMethod() . ' ' . $request->getUri()->getPath() . ' ' . (string)$request->getBody();
}

$expected = ['POST /v3/domain/view {}', 'POST /v3/single_sender_emails/view {}'];
$suite->check('only the two view endpoints are requested', $requests === $expected, implode(', ', $requests));

$hasKey = ($http->getRequests()[0]->getHeaderLine('X-Smtp2go-Api-Key') === 'api-test');
$suite->check('the API key is sent in its header', $hasKey);

$account = $describe($checker->checkAccount());
$suite->check('a key that can read everything raises nothing', $account === '', $account);

$good = $describe($checker->checkDomain(DomainDns::fetch($dns, 'good.test')));
$passed = $good === 'ok: good.test is authenticated with SMTP2GO';
$suite->check('a domain with DKIM and return-path verified passes', $passed, $good);

$newCheck = $checker->checkDomain(DomainDns::fetch($dns, 'new.test'))->first();
$isListed = str_contains($newCheck->getDetail(), 'CNAME em2.new.test');
$suite->check('a domain missing its return path lists that record', $isListed, $newCheck->getDetail());
$isNotOk = ($newCheck->getLevel() !== SenderCheckLevel::OK);
$suite->check('a domain missing its return path doesn\'t pass', $isNotOk, $newCheck->getLevel()->value);

$address = new EmailAddress('me@single.test');
$single = $describe($checker->checkAddress($address, DomainDns::fetch($dns, 'single.test')));
$isRecognised = ($single === 'warning: Only the address is verified');
$suite->check('a verified single sender is recognised, with a DMARC warning', $isRecognised, $single);

$denied = [400, ['data' => ['error_code' => 'E_ApiResponseCodes.ENDPOINT_PERMISSION_DENIED']]];
[$limited] = $createChecker(['/v3/single_sender_emails/view' => $denied]);
$account = $describe($limited->checkAccount());
$expected = 'warning: The API key can\'t read all the senders';
$suite->check('a key denied an endpoint with a 400 is reported', $account === $expected, $account);

$createWithBadKey = function () use ($createChecker) : void {
    $createChecker(['/v3/domain/view' => [401, ['data' => ['error' => 'Bad key']]]]);
};

$suite->checkThrows('a rejected key is an error', ProviderLookupException::class, $createWithBadKey);

$withoutApi = Smtp2GoSenderChecker::fetch('smtp2go', null);
$account = $describe($withoutApi->checkAccount());
$suite->check('without an api_key it says so', $account === 'info: The senders can\'t be checked', $account);

$suite->finish();
