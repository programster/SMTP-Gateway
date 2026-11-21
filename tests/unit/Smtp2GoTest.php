<?php

/*
 * The SMTP2GO provider: removing the client's X-Smtp2go-* headers, and its sender checks against a faked API, which
 * must only ever POST to its view-only endpoints.
 */

declare(strict_types = 1);

require_once __DIR__ . '/TestSuite.php';
require_once __DIR__ . '/Fakes.php';

$suite = new TestSuite('SMTP2GO');
$provider = new Smtp2GoProvider('smtp2go', 'relay-user', 'relay-password', '');
$messageId = '11111111-2222-3333-4444-555555555555';

$createWithoutPassword = function () : void {
    new Smtp2GoProvider('smtp2go', 'relay-user', ' ', '');
};

$suite->checkThrows('a provider without a password is refused', ConfigurationException::class, $createWithoutPassword);
$suite->check('it has a webhook', ProviderType::SMTP2GO->hasWebhook());

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
    return [Smtp2GoSenderChecker::fetch('smtp2go', $api, false, '', ''), $http];
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

$expected = ['POST /v3/domain/view {}', 'POST /v3/single_sender_emails/view {}', 'POST /v3/webhook/view {}'];
$suite->check('only the view endpoints are requested', $requests === $expected, implode(', ', $requests));

$hasKey = ($http->getRequests()[0]->getHeaderLine('X-Smtp2go-Api-Key') === 'api-test');
$suite->check('the API key is sent in its header', $hasKey);

$account = $describe($checker->checkAccount());
$isOnlyPrompt = ($account === 'info: Delivery events aren\'t tracked');
$suite->check('with no webhook, it only says events aren\'t tracked', $isOnlyPrompt, $account);

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
$expected = 'warning: The API key can\'t read all the senders | info: Delivery events aren\'t tracked';
$suite->check('a key denied an endpoint with a 400 is reported', $account === $expected, $account);

$createWithBadKey = function () use ($createChecker) : void {
    $createChecker(['/v3/domain/view' => [401, ['data' => ['error' => 'Bad key']]]]);
};

$suite->checkThrows('a rejected key is an error', ProviderLookupException::class, $createWithBadKey);

$withoutApi = Smtp2GoSenderChecker::fetch('smtp2go', null, false, '', '');
$account = $describe($withoutApi->checkAccount());
$expected = 'info: The senders can\'t be checked | info: Delivery events aren\'t tracked';
$suite->check('without an api_key it says so', $account === $expected, $account);

/**
 * Get the account checks of a provider that sends with its API key, whose key has these permissions.
 */
$describeApiSender = function (array $endpoints) use ($responses, $describe) : string {
    $replies = array_merge($responses, ['/v3/api_keys/permissions' => [200, ['data' => $endpoints]]]);
    $factory = new \GuzzleHttp\Psr7\HttpFactory();
    $headers = ['X-Smtp2go-Api-Key' => 'api-test'];
    $api = new JsonApiClient('SMTP2GO', 'https://api.smtp2go.com/v3', $headers, new FakeHttpClient($replies), $factory);
    return $describe(Smtp2GoSenderChecker::fetch('smtp2go', $api, true, '', '')->checkAccount());
};

$views = ['/domain/view', '/single_sender_emails/view'];
$account = $describeApiSender($views);
$passed = ($account === 'error: The API key can\'t send email | info: Delivery events aren\'t tracked');
$suite->check('a key that has to send, without /email/mime, is an error', $passed, $account);
$account = $describeApiSender([...$views, '/email/mime']);
$isOnlyPrompt = ($account === 'info: Delivery events aren\'t tracked');
$suite->check('a key that has to send, with /email/mime, is fine', $isOnlyPrompt, $account);

// --- Which credentials are needed

$value = function (string $text) : ConfigValue {
    return ConfigValue::createFromString($text);
};

$smtpOnly = new Smtp2GoSettings($value('user'), $value('pass'), $value(''), $value(''));
$suite->check('an SMTP login alone sends over SMTP', $smtpOnly->createProvider('p') instanceof Smtp2GoProvider);
$apiOnly = new Smtp2GoSettings($value(''), $value(''), $value('api-key'), $value(''));
$suite->check('an API key alone sends with the API', $apiOnly->createProvider('p') instanceof Smtp2GoApiProvider);
$both = new Smtp2GoSettings($value('user'), $value('pass'), $value('api-key'), $value(''));
$suite->check('with both, it sends over SMTP', $both->createProvider('p') instanceof Smtp2GoProvider);

$createWithNeither = function () use ($value) : void {
    new Smtp2GoSettings($value(''), $value(''), $value(''), $value(''));
};

$suite->checkThrows('neither a login nor a key is refused', InvalidInputException::class, $createWithNeither);

$createWithHalfLogin = function () use ($value) : void {
    new Smtp2GoSettings($value('user'), $value(''), $value('api-key'), $value(''));
};

$suite->checkThrows('a username without a password is refused', InvalidInputException::class, $createWithHalfLogin);

$createWithPasswordOnly = function () use ($value) : void {
    new Smtp2GoSettings($value(''), $value('pass'), $value('api-key'), $value(''));
};

$suite->checkThrows('a password without a username is refused', InvalidInputException::class, $createWithPasswordOnly);

// --- Sending with the API key

$okReply = [200, ['data' => ['succeeded' => 1, 'failed' => 0, 'failures' => [], 'email_id' => 'smtp2go-id']]];

/**
 * Create an email to the given recipients, with the given To and any other headers.
 */
$createEmail = function (string $headers, string ...$to) use ($messageId) : OutgoingEmail {
    $raw = new RawEmail("From: a@good.test\r\n{$headers}Subject: s\r\n\r\nbody\r\n");
    $addresses = [];

    foreach ($to as $address)
    {
        $addresses[] = new EmailAddress($address);
    }

    $recipients = new EmailAddressCollection(...$addresses);
    return new OutgoingEmail($messageId, new EmailAddress('a@good.test'), $recipients, $raw);
};

/**
 * Create an API provider against a faked API that gives a reply, returning it with its HTTP client.
 */
$createApiProvider = function (array $reply) : array {
    $http = new FakeHttpClient(['/v3/email/mime' => $reply]);
    return [new Smtp2GoApiProvider('smtp2go', 'api-key', '', $http, new \GuzzleHttp\Psr7\HttpFactory()), $http];
};

/**
 * Get the email a faked API was sent, decoded.
 */
$readSentEmail = function (FakeHttpClient $http) : RawEmail {
    $payload = json_decode((string)$http->getRequests()[0]->getBody(), true);
    return new RawEmail(base64_decode($payload['mime_email'], true));
};

[$apiProvider, $http] = $createApiProvider($okReply);
$email = $createEmail("To: b@x.test\r\nX-Smtp2go-Test: 1\r\nBcc: old@x.test\r\n", 'b@x.test', 'hidden@x.test');
$id = $apiProvider->send($email);
$sent = $readSentEmail($http);
$request = $http->getRequests()[0];
$suite->check('it returns SMTP2GO\'s ID for the email', $id === 'smtp2go-id', $id);

$isRequestRight = (
       $request->getMethod() === 'POST'
    && $request->getUri()->getPath() === '/v3/email/mime'
    && $request->getHeaderLine('X-Smtp2go-Api-Key') === 'api-key'
);

$suite->check('it posts to /email/mime with the key', $isRequestRight);
$bcc = $sent->getHeaderValues('Bcc');
$isInBcc = ($bcc === ['hidden@x.test']);
$suite->check('a recipient not in To or Cc is put in Bcc, replacing any', $isInBcc, implode('|', $bcc));
$suite->check('the client\'s X-Smtp2go-* headers are removed', $sent->getHeaderValues('X-Smtp2go-Test') === []);

[$apiProvider, $http] = $createApiProvider($okReply);
$apiProvider->send($createEmail("To: B@X.test\r\nCc: c@x.test\r\n", 'b@x.test', 'C@x.test'));
$sent = $readSentEmail($http);
$suite->check('matching recipients, ignoring case, need no Bcc', $sent->getHeaderValues('Bcc') === []);

$manyRecipients = [];

for ($i = 0; $i < 60; $i++)
{
    $manyRecipients[] = "someone-with-a-longer-address-{$i}@example.test";
}

[$apiProvider, $http] = $createApiProvider($okReply);
$apiProvider->send($createEmail("To: b@x.test\r\n", 'b@x.test', ...$manyRecipients));
$sentText = $readSentEmail($http)->toString();
$longestLine = max(array_map('strlen', explode("\r\n", $sentText)));
$suite->check('a long Bcc list is folded within the line limit', $longestLine <= 998, (string)$longestLine);
$sentBcc = $readSentEmail($http)->getAddressesInHeader('Bcc');
$suite->check('and every hidden recipient is in it', count($sentBcc) === 60, (string)count($sentBcc));

[$apiProvider, $http] = $createApiProvider($okReply);

$sendToSomeoneElse = function () use ($apiProvider, $createEmail) : void {
    $apiProvider->send($createEmail("To: b@x.test, not-a-recipient@x.test\r\n", 'b@x.test'));
};

$name = 'an email whose To lists a non-recipient is refused';
$suite->checkThrows($name, ProviderPermanentFailureException::class, $sendToSomeoneElse);
$suite->check('and nothing is sent to SMTP2GO', count($http->getRequests()) === 0);

$tooManyHidden = [];

for ($i = 0; $i < 101; $i++)
{
    $tooManyHidden[] = "h{$i}@x.test";
}

[$apiProvider, $http] = $createApiProvider($okReply);

$sendTooManyHidden = function () use ($apiProvider, $createEmail, $tooManyHidden) : void {
    $apiProvider->send($createEmail("To: b@x.test\r\n", 'b@x.test', ...$tooManyHidden));
};

$suite->checkThrows('over 100 Bcc recipients is refused', ProviderPermanentFailureException::class, $sendTooManyHidden);

$failedReply = [200, ['data' => ['succeeded' => 0, 'failed' => 1, 'failures' => ['rejected'], 'email_id' => 'x']]];
$deniedReply = [400, ['data' => ['error_code' => 'E_ApiResponseCodes.ENDPOINT_PERMISSION_DENIED']]];

$replies = [
    'a 200 with failures is a permanent failure' => [$failedReply, ProviderPermanentFailureException::class],
    'a 400 is a permanent failure' => [[400, ['data' => ['error' => 'Bad']]], ProviderPermanentFailureException::class],
    'a 401 makes it unavailable' => [[401, ['data' => []]], ProviderUnavailableException::class],
    'a key without permission makes it unavailable' => [$deniedReply, ProviderUnavailableException::class],
    'a 429 is a rate limit' => [[429, ['data' => []]], ProviderRateLimitedException::class],
    'a 503 is a temporary failure' => [[503, ['data' => []]], ProviderTemporaryFailureException::class],
];

foreach ($replies as $name => [$reply, $exceptionClass])
{
    [$apiProvider] = $createApiProvider($reply);

    $sendEmail = function () use ($apiProvider, $createEmail) : void {
        $apiProvider->send($createEmail("To: b@x.test\r\n", 'b@x.test'));
    };

    $suite->checkThrows($name, $exceptionClass, $sendEmail);
}

// --- The webhook

$webhookSecret = '0123456789abcdef0123456789abcdef';
$hooked = new Smtp2GoProvider('smtp2go', 'relay-user', 'relay-password', $webhookSecret);
$requestFactory = new \Slim\Psr7\Factory\ServerRequestFactory();

/**
 * Create a webhook request with an Authorization header.
 */
$createWebhookRequest = function (string $auth) use ($requestFactory) : \Psr\Http\Message\ServerRequestInterface {
    $request = $requestFactory->createServerRequest('POST', '/webhooks/smtp2go');
    return ($auth === '') ? $request : $request->withHeader('Authorization', $auth);
};

$basicAuth = 'Basic ' . base64_encode("webhook:{$webhookSecret}");

$delivered = json_encode([
    'event' => 'delivered', 'time' => '2026-11-21T16:33:58Z', 'email_id' => '1xUo2C-abc', 'rcpt' => 'a@x.test',
]);

$events = iterator_to_array($hooked->parseWebhook($delivered, $createWebhookRequest($basicAuth)));
$first = $events[0] ?? null;

$isRead = (
       $first instanceof ProviderEvent
    && $first->getType() === EmailEventType::DELIVERED
    && $first->getProviderMessageId() === '1xUo2C-abc'
    && $first->getRecipient() === 'a@x.test'
    && $first->getOccurredAt()->format('U') === (string)strtotime('2026-11-21T16:33:58Z')
);

$suite->check('a JSON event with the secret is read, by SMTP2GO\'s ID', $isRead);

$again = iterator_to_array($hooked->parseWebhook($delivered, $createWebhookRequest("Bearer {$webhookSecret}")));
$isSameId = ($first->getProviderEventId() === $again[0]->getProviderEventId());
$suite->check('the secret works as a bearer token, and a retry has the same event ID', $isSameId);

$form = http_build_query(['event' => 'bounce', 'bounce' => 'hard', 'email_id' => 'id-2', 'rcpt' => 'b@x.test']);
$bounced = iterator_to_array($hooked->parseWebhook($form, $createWebhookRequest($basicAuth)));
$suite->check('a form encoded hard bounce is a bounce', $bounced[0]->getType() === EmailEventType::BOUNCED);

$soft = json_encode(['event' => 'bounce', 'bounce' => 'soft', 'email_id' => 'id-3', 'message' => '451 later']);
$deferred = iterator_to_array($hooked->parseWebhook($soft, $createWebhookRequest($basicAuth)));
$isDeferred = ($deferred[0]->getType() === EmailEventType::DEFERRED && $deferred[0]->getDetail() === '451 later');
$suite->check('a soft bounce is deferred, with its reason', $isDeferred);

$eventTypes = [
    'processed' => EmailEventType::PROCESSED,
    'reject' => EmailEventType::DROPPED,
    'spam' => EmailEventType::SPAM_REPORT,
    'unsubscribe' => EmailEventType::UNSUBSCRIBED,
    'open' => EmailEventType::OPENED,
    'click' => EmailEventType::CLICKED,
    'resubscribe' => EmailEventType::OTHER,
];

foreach ($eventTypes as $eventName => $eventType)
{
    $body = json_encode(['event' => $eventName, 'email_id' => 'id-4']);
    $read = iterator_to_array($hooked->parseWebhook($body, $createWebhookRequest($basicAuth)));
    $suite->check("its {$eventName} event is read", $read[0]->getType() === $eventType);
}

$sms = json_encode(['event' => 'sms_delivered', 'message_id' => 'x']);
$smsEvents = $hooked->parseWebhook($sms, $createWebhookRequest($basicAuth));
$suite->check('its SMS events are ignored', count($smsEvents) === 0);

foreach (['a wrong secret' => 'Bearer wrong-secret', 'no secret' => ''] as $case => $authorization)
{
    $parseBadAuth = function () use ($hooked, $delivered, $createWebhookRequest, $authorization) : void {
        $hooked->parseWebhook($delivered, $createWebhookRequest($authorization));
    };

    $suite->checkThrows("{$case} is refused", RequestRejectedException::class, $parseBadAuth);
}

$parseNotEvent = function () use ($hooked, $createWebhookRequest, $basicAuth) : void {
    $hooked->parseWebhook('not an event', $createWebhookRequest($basicAuth));
};

$suite->checkThrows('a body that isn\'t an event is refused', InvalidInputException::class, $parseNotEvent);
$suite->check('without a secret the webhook is off', $provider->isWebhookEnabled() === false);
$suite->check('with one it\'s on', $hooked->isWebhookEnabled());
$apiHooked = Smtp2GoApiProvider::createUsingApiKey('smtp2go', 'api-key', $webhookSecret);
$suite->check('the API route has the webhook too', $apiHooked->isWebhookEnabled());

$createWithShortSecret = function () : void {
    new Smtp2GoProvider('smtp2go', 'relay-user', 'relay-password', 'short');
};

$suite->checkThrows('a short secret is refused', ConfigurationException::class, $createWithShortSecret);

$extractQueuedId = new ReflectionMethod(SmtpRelayProvider::class, 'extractQueuedId');
$eximId = $extractQueuedId->invoke(null, '250 OK id=1xUmcw-FnQW0hPxdkX-Lxmt');
$suite->check('SMTP2GO\'s ID is read from its SMTP reply', $eximId === '1xUmcw-FnQW0hPxdkX-Lxmt', $eximId);
$postfixId = $extractQueuedId->invoke(null, '250 Ok: queued as 9C2Kq0aJQw2');
$suite->check('and other servers\' still are', $postfixId === '9C2Kq0aJQw2', $postfixId);

$single = Smtp2GoWebhook::extractConfigured(['data' => ['id' => 1, 'url' => 'https://a.test/x']]);
$listed = Smtp2GoWebhook::extractConfigured(['data' => [['id' => 1, 'url' => 'https://a.test/x']]]);
$nested = Smtp2GoWebhook::extractConfigured(['data' => ['webhooks' => [['id' => 1, 'url' => 'https://a.test/x']]]]);
$none = Smtp2GoWebhook::extractConfigured(['data' => []]);
$isExtracted = (count($single) === 1 && count($listed) === 1 && count($nested) === 1 && count($none) === 0);
$suite->check('a webhook list is read in any of its possible shapes', $isExtracted);

// --- Setting up the webhook

$hookUrl = 'https://smtp.example.org/webhooks/smtp2go';
$httpFactory = new \GuzzleHttp\Psr7\HttpFactory();

/**
 * Get the path and decoded body of each request an installer made.
 */
$readRequests = function (FakeHttpClient $client) : array {
    $made = [];

    foreach ($client->getRequests() as $made_request)
    {
        $made[] = [$made_request->getUri()->getPath(), json_decode((string)$made_request->getBody(), true)];
    }

    return $made;
};

$client = new FakeHttpClient([
    '/v3/webhook/view' => [200, ['data' => []]],
    '/v3/webhook/add' => [200, ['data' => ['id' => 7]]],
]);

(new Smtp2GoWebhookInstaller('api-key', $client, $httpFactory))->install($hookUrl, $webhookSecret);
$made = $readRequests($client);
$added = $made[1][1] ?? [];

$isAdded = (
       ($made[1][0] ?? '') === '/v3/webhook/add'
    && $added['url'] === $hookUrl
    && $added['auth_header_type'] === 'bearer'
    && $added['auth_header_value'] === $webhookSecret
    && $added['events'] === Smtp2GoWebhook::RECORDED_EVENTS
    && $added['output_format'] === 'json'
);

$suite->check('setting it up adds a webhook, with a bearer token and every event', $isAdded, json_encode($made));

$client = new FakeHttpClient([
    '/v3/webhook/view' => [200, ['data' => [['id' => 9, 'url' => 'https://old:pw@smtp.example.org/webhooks/smtp2go']]]],
    '/v3/webhook/edit' => [200, ['data' => ['id' => 9]]],
]);

(new Smtp2GoWebhookInstaller('api-key', $client, $httpFactory))->install($hookUrl, $webhookSecret);
$made = $readRequests($client);
$isEdited = (($made[1][0] ?? '') === '/v3/webhook/edit' && ($made[1][1]['id'] ?? 0) === 9 && count($made) === 2);
$suite->check('one already posting here is updated, not duplicated', $isEdited, json_encode($made));

$installOverHttp = function () use ($httpFactory, $webhookSecret) : void {
    $client = new FakeHttpClient([]);
    (new Smtp2GoWebhookInstaller('api-key', $client, $httpFactory))->install('http://a.test/x', $webhookSecret);
};

$suite->checkThrows('a webhook that isn\'t https is refused', InvalidInputException::class, $installOverHttp);

$installDenied = function () use ($httpFactory, $hookUrl, $webhookSecret, $denied) : void {
    $client = new FakeHttpClient(['/v3/webhook/view' => $denied]);
    (new Smtp2GoWebhookInstaller('api-key', $client, $httpFactory))->install($hookUrl, $webhookSecret);
};

$suite->checkThrows('a key without the permission is refused', InvalidInputException::class, $installDenied);

$noKey = new Smtp2GoSettings($value('user'), $value('pass'), $value(''), $value(''));

$createInstallerWithoutKey = function () use ($noKey) : void {
    $noKey->createWebhookInstaller();
};

$name = 'setting it up without an API key is refused';
$suite->checkThrows($name, InvalidInputException::class, $createInstallerWithoutKey);

$limitReply = [400, ['data' => ['error' => 'Webhook limit reached', 'error_code' => 'E_ApiResponseCodes.LIMIT']]];
$client = new FakeHttpClient(['/v3/webhook/view' => [200, ['data' => []]], '/v3/webhook/add' => $limitReply]);
$limitMessage = '';

try
{
    (new Smtp2GoWebhookInstaller('api-key', $client, $httpFactory))->install($hookUrl, $webhookSecret);
}
catch (InvalidInputException $e)
{
    $limitMessage = $e->getMessage();
}

$isExplained = (str_contains($limitMessage, 'Webhook limit reached') && str_contains($limitMessage, 'free plan'));
$suite->check('a refused webhook says why, and warns about the plan\'s limit', $isExplained, $limitMessage);

$suite->finish();
