<?php

/*
 * The sender checks: a sending domain's own DNS (DMARC, SPF, somewhere to receive email), and whether SendGrid has
 * each sender verified and its event webhook set up. DNS and SendGrid's API are both faked, with made-up answers, so
 * every case can be checked without the network.
 */

declare(strict_types = 1);

use Psr\Http\Message\RequestInterface;

require_once __DIR__ . '/TestSuite.php';
require_once __DIR__ . '/Fakes.php';


$suite = new TestSuite('Sender checks');
$publicKey = 'MFkwEwYHKoZIzj0CAQYIKoZIzj0DAQcDQgAEexample';
$webhookUrl = 'https://smtp.example.org/webhooks/sendgrid';

$dns = new FakeDnsResolver(
    [
        '_dmarc.good.test' => ['v=DMARC1; p=reject; rua=mailto:d@good.test'],
        'good.test' => ['v=spf1 include:sendgrid.net -all', 'google-site-verification=abc'],
        '_dmarc.parent.test' => ['v=DMARC1; p=none; sp=quarantine'],
        '_dmarc.strict.test' => ['v=DMARC1; p=reject; adkim=s'],
        '_dmarc.monitor.test' => ['v=DMARC1; p=none'],
        'monitor.test' => ['v=spf1 include:a.test ~all', 'v=spf1 include:b.test ~all'],
        'open.test' => ['v=spf1 +all'],
        '_dmarc.twice.test' => ['v=DMARC1; p=reject', 'v=DMARC1; p=none'],
        '_dmarc.gmail.com' => ['v=DMARC1; p=none'],
    ],
    ['good.test', 'sub.parent.test', 'strict.test', 'monitor.test', 'gmail.com', 'single.test', 'some.test'],
    ['broken.test']
);

/**
 * Get the checks with a given source and level, as "level: title" lines, for comparing.
 */
$describe = function (SenderCheckCollection $checks, string $source) : string {
    $lines = [];

    foreach ($checks as $check)
    {
        /* @var $check SenderCheck */
        if ($check->getSource() === $source)
        {
            $lines[] = $check->getLevel()->value . ': ' . $check->getTitle();
        }
    }

    return implode(' | ', $lines);
};

/**
 * Get the details of every check, joined, for checking what they say.
 */
$details = function (SenderCheckCollection $checks) : string {
    $parts = [];

    foreach ($checks as $check)
    {
        /* @var $check SenderCheck */
        $parts[] = $check->getDetail();
    }

    return implode(' ', $parts);
};

// --- DNS

$good = DomainDnsChecker::check(DomainDns::fetch($dns, 'good.test'));
$expected = 'ok: DMARC is enforced (p=reject) | ok: One SPF record';
$suite->check('a well set-up domain passes', $describe($good, 'DNS') === $expected, $describe($good, 'DNS'));

$subdomain = DomainDns::fetch($dns, 'sub.parent.test');
$isQuarantined = ($subdomain->getDmarcPolicy() === DmarcPolicy::QUARANTINE);
$isInherited = ($isQuarantined && $subdomain->getDmarcLocation() === 'parent.test');
$suite->check('a subdomain uses its parent\'s sp= policy', $isInherited, $subdomain->getDmarcPolicy()->value);

$missing = $describe(DomainDnsChecker::check(DomainDns::fetch($dns, 'single.test')), 'DNS');
$expected = 'warning: No DMARC record | info: No SPF record';
$suite->check('a missing DMARC record is a warning', $missing === $expected, $missing);

$monitor = $describe(DomainDnsChecker::check(DomainDns::fetch($dns, 'monitor.test')), 'DNS');
$expected = 'info: DMARC is only monitoring (p=none) | error: More than one SPF record';
$suite->check('p=none is noted, and two SPF records are an error', $monitor === $expected, $monitor);

$open = $describe(DomainDnsChecker::check(DomainDns::fetch($dns, 'open.test')), 'DNS');
$isOpenFlagged = str_contains($open, 'warning: SPF allows anyone') && str_contains($open, 'It can\'t receive email');
$suite->check('+all and no mail host are warnings', $isOpenFlagged, $open);

$twice = $describe(DomainDnsChecker::check(DomainDns::fetch($dns, 'twice.test')), 'DNS');
$suite->check('two DMARC records are an error', str_contains($twice, 'error: More than one DMARC record'), $twice);

$gmail = $describe(DomainDnsChecker::check(DomainDns::fetch($dns, 'gmail.com')), 'DNS');
$suite->check('a free email domain is flagged', str_starts_with($gmail, 'warning: A free email domain'), $gmail);

// --- SendGrid

$domains = [
    [
        'domain' => 'good.test', 'valid' => true, 'last_validation_attempt_at' => 1700000000,
        'dns' => ['dkim1' => ['valid' => true, 'type' => 'cname', 'host' => 's1._domainkey.good.test', 'data' => 'x']],
    ],
    [
        'domain' => 'half.test', 'valid' => false, 'last_validation_attempt_at' => 1700000000,
        'dns' => [
            'mail_cname' => [
                'valid' => false, 'type' => 'cname', 'host' => 'em1.half.test', 'data' => 'u1.wl.sendgrid.net',
            ],
            'dkim1' => ['valid' => true, 'type' => 'cname', 'host' => 's1._domainkey.half.test', 'data' => 'x'],
        ],
    ],
    ['domain' => 'parent.test', 'valid' => true, 'dns' => []],
    ['domain' => 'strict.test', 'valid' => true, 'dns' => []],
];

$senders = [
    'results' => [
        ['id' => 1, 'from_email' => 'Me@Single.test', 'verified' => true],
        ['id' => 2, 'from_email' => 'pending@single.test', 'verified' => false],
        ['id' => 3, 'from_email' => 'one@some.test', 'verified' => true],
        ['id' => 4, 'from_email' => 'me@gmail.com', 'verified' => true],
        ['id' => 5, 'from_email' => 'me@monitor.test', 'verified' => true],
    ],
];

$webhooks = [
    'webhooks' => [
        [
            'url' => $webhookUrl . '/', 'enabled' => true, 'public_key' => $publicKey, 'processed' => true,
            'delivered' => true, 'deferred' => true, 'bounce' => true, 'dropped' => false,
        ],
    ],
];

$fullAccess = [
    '/v3/scopes' => [200, ['scopes' => ['mail.send', 'whitelabel.read', 'user.webhooks.event.settings.read']]],
    '/v3/whitelabel/domains' => [200, $domains],
    '/v3/verified_senders' => [200, $senders],
    '/v3/user/webhooks/event/settings/all' => [200, $webhooks],
];

/**
 * Create a client for SendGrid's API that sends its requests to a fake.
 */
$createSendGridApi = function (FakeHttpClient $http) : JsonApiClient {
    $headers = ['Authorization' => 'Bearer SG.test-key'];
    $factory = new \GuzzleHttp\Psr7\HttpFactory();
    return new JsonApiClient('SendGrid', 'https://api.sendgrid.com', $headers, $http, $factory);
};

$http = new FakeHttpClient($fullAccess);
$api = $createSendGridApi($http);
$checker = SendGridSenderChecker::fetch('sendgrid', $api, $publicKey, $webhookUrl);

$isReadOnly = true;

foreach ($http->getRequests() as $request)
{
    /* @var $request RequestInterface */
    $isAuthorised = ($request->getHeaderLine('Authorization') === 'Bearer SG.test-key');
    $isReadOnly = $isReadOnly && $request->getMethod() === 'GET' && $isAuthorised;
}

$suite->check('it only makes GET requests, with the API key', $isReadOnly, (string)count($http->getRequests()));

$authenticated = $describe($checker->checkDomain(DomainDns::fetch($dns, 'good.test')), 'sendgrid');
$expected = 'ok: good.test is authenticated with SendGrid';
$suite->check('an authenticated domain passes', $authenticated === $expected, $authenticated);

$halfChecks = $checker->checkDomain(DomainDns::fetch($dns, 'half.test'));
$half = $describe($halfChecks, 'sendgrid');
$namesRecord = str_contains($details($halfChecks), 'CNAME em1.half.test → u1.wl.sendgrid.net');
$isUnfinished = (str_starts_with($half, 'error:') && $namesRecord);
$suite->check('an unfinished domain lists its missing records', $isUnfinished, $half);

$parent = $describe($checker->checkDomain(DomainDns::fetch($dns, 'sub.parent.test')), 'sendgrid');
$expected = 'ok: Covered by parent.test\'s authentication';
$suite->check('a subdomain is covered by its parent', $parent === $expected, $parent);

$strictResolver = new FakeDnsResolver(['_dmarc.sub.strict.test' => ['v=DMARC1; p=reject; adkim=s']], []);
$strictDns = DomainDns::fetch($strictResolver, 'sub.strict.test');
$strict = $describe($checker->checkDomain($strictDns), 'sendgrid');
$suite->check('but not under strict DKIM alignment', str_starts_with($strict, 'warning: Only strict.test'), $strict);

$singleDns = DomainDns::fetch($dns, 'single.test');
$single = $describe($checker->checkAddress(new EmailAddress('me@single.test'), $singleDns), 'sendgrid');
$expected = 'warning: Only the address is verified';
$suite->check('a single sender alone is a warning (case ignored)', $single === $expected, $single);

$enforcedChecks = $checker->checkAddress(new EmailAddress('me@monitor.test'), DomainDns::fetch($dns, 'monitor.test'));
$enforced = $describe($enforcedChecks, 'sendgrid');
$suite->check('with p=none, a single sender is still only a warning', $enforced === $expected, $enforced);

$rejectDns = DomainDns::fetch(new FakeDnsResolver(['_dmarc.single.test' => ['v=DMARC1; p=reject']], []), 'single.test');
$rejectChecks = $checker->checkAddress(new EmailAddress('me@single.test'), $rejectDns);
$reject = $describe($rejectChecks, 'sendgrid');
$saysRefused = str_contains($details($rejectChecks), 'receivers will refuse it');
$isRejected = ($reject === 'error: Only the address is verified' && $saysRefused);
$suite->check('with p=reject, a single sender is an error', $isRejected, $reject);

$pending = $describe($checker->checkAddress(new EmailAddress('pending@single.test'), $singleDns), 'sendgrid');
$isPending = ($pending === 'error: pending@single.test isn\'t verified yet');
$suite->check('an unclicked verification is an error', $isPending, $pending);

$unknown = $describe($checker->checkAddress(new EmailAddress('who@single.test'), $singleDns), 'sendgrid');
$isUnknown = ($unknown === 'error: SendGrid hasn\'t verified who@single.test');
$suite->check('an unverified address is an error', $isUnknown, $unknown);

$goodDns = DomainDns::fetch($dns, 'good.test');
$validAddress = $describe($checker->checkAddress(new EmailAddress('anyone@good.test'), $goodDns), 'sendgrid');
$isCovered = ($validAddress === 'ok: good.test is authenticated with SendGrid');
$suite->check('an address at an authenticated domain passes', $isCovered, $validAddress);

$someChecks = $checker->checkDomain(DomainDns::fetch($dns, 'some.test'));
$some = $describe($someChecks, 'sendgrid');
$listsAddress = str_contains($details($someChecks), 'one@some.test');
$isPartial = (str_starts_with($some, 'error: Only some') && $listsAddress);
$suite->check('a domain with only some verified addresses is an error', $isPartial, $some);

$gmailChecks = $checker->checkAddress(new EmailAddress('me@gmail.com'), DomainDns::fetch($dns, 'gmail.com'));
$isFreeAdvice = str_contains($details($gmailChecks), 'gmail.com can\'t be authenticated');
$suite->check('a free email address is told to use its own domain', $isFreeAdvice, $details($gmailChecks));

$anyChecks = $checker->checkAnySender();
$anyDetails = $details($anyChecks);
$listsVerified = (str_contains($anyDetails, '*@good.test') && !str_contains($anyDetails, '*@half.test'));
$suite->check('"anyone" lists only what\'s verified', $listsVerified, $details($anyChecks));

$account = $describe($checker->checkAccount(), 'sendgrid');
$expected = 'ok: The API key can send email | ok: The event webhook is set up | info: Some events aren\'t sent';
$suite->check('a set-up account passes, noting missing events', $account === $expected, $account);

/**
 * Create a checker with some of the full-access responses replaced.
 */
$createChecker = function (
    array $replacements,
    string $key
) use ($fullAccess, $webhookUrl, $createSendGridApi) : SendGridSenderChecker {
    $http = new FakeHttpClient(array_merge($fullAccess, $replacements));
    $api = $createSendGridApi($http);
    return SendGridSenderChecker::fetch('sendgrid', $api, $key, $webhookUrl);
};

$wrongKey = $describe($createChecker([], 'MFkwEwYHKoZIzj0CAQYIKoZIzj0DAQcDQgAEother')->checkAccount(), 'sendgrid');
$isWrongKey = str_contains($wrongKey, 'error: The webhook\'s public key is wrong');
$suite->check('a different public key is an error', $isWrongKey, $wrongKey);

$noWebhook = ['/v3/user/webhooks/event/settings/all' => [200, ['webhooks' => []]]];
$missingWebhook = $describe($createChecker($noWebhook, $publicKey)->checkAccount(), 'sendgrid');
$isMissing = str_contains($missingWebhook, 'error: SendGrid isn\'t sending events here');
$suite->check('a missing webhook is an error, if a key is set', $isMissing, $missingWebhook);

$untracked = $describe($createChecker($noWebhook, '')->checkAccount(), 'sendgrid');
$suite->check('and only a note, if not', str_contains($untracked, 'info: Delivery events aren\'t tracked'), $untracked);

$unsignedEntry = ['url' => $webhookUrl, 'enabled' => true];
$unsignedHook = ['/v3/user/webhooks/event/settings/all' => [200, ['webhooks' => [$unsignedEntry]]]];
$unsigned = $describe($createChecker($unsignedHook, $publicKey)->checkAccount(), 'sendgrid');
$isUnsigned = str_contains($unsigned, 'error: The event webhook isn\'t signed');
$suite->check('an unsigned webhook is an error', $isUnsigned, $unsigned);

$restricted = $createChecker(
    [
        '/v3/scopes' => [200, ['scopes' => ['mail.send']]],
        '/v3/whitelabel/domains' => [403, ['errors' => [['message' => 'access forbidden']]]],
        '/v3/verified_senders' => [403, ['errors' => [['message' => 'access forbidden']]]],
        '/v3/user/webhooks/event/settings/all' => [403, ['errors' => [['message' => 'access forbidden']]]],
    ],
    $publicKey
);

$restrictedAccount = $describe($restricted->checkAccount(), 'sendgrid');

$isRestrictedNoted = (
       str_contains($restrictedAccount, 'warning: Can\'t list authenticated domains')
    && str_contains($restrictedAccount, 'warning: Can\'t list verified senders')
    && str_contains($restrictedAccount, 'info: Can\'t check the event webhook')
);

$suite->check('a Mail Send-only key says what it can\'t check', $isRestrictedNoted, $restrictedAccount);
$restrictedDomain = $describe($restricted->checkDomain(DomainDns::fetch($dns, 'good.test')), 'sendgrid');
$isUnchecked = ($restrictedDomain === 'warning: Can\'t check good.test');
$suite->check('and doesn\'t claim a domain is unverified', $isUnchecked, $restrictedDomain);

$noScopes = ['/v3/scopes' => [200, ['scopes' => []]]];
$cantSend = $describe($createChecker($noScopes, $publicKey)->checkAccount(), 'sendgrid');
$isCantSend = str_starts_with($cantSend, 'error: The API key can\'t send email');
$suite->check('a key without Mail Send is an error', $isCantSend, $cantSend);

try
{
    $createChecker(['/v3/scopes' => [401, ['errors' => [['message' => 'authorization required']]]]], $publicKey);
    $rejected = 'no exception';
}
catch (ProviderLookupException $e)
{
    $rejected = $e->getMessage();
}

$isRejectedKey = str_contains($rejected, 'rejected the API key');
$suite->check('a rejected API key can\'t be checked at all', $isRejectedKey, $rejected);

// --- Together

$mailcow = CustomSenderChecker::fetch('mailcow', $dns, '10.0.0.5', '');
$checks = new SenderChecks($dns, $checker, $mailcow);

$rules = new AddressRuleSet(
    AddressRule::createFromString('*@good.test'),
    AddressRule::createFromString('me@single.test'),
    AddressRule::createFromString('*@broken.test')
);

$groups = $checks->checkSenders($rules, ['*@good.test' => ['Website', 'Billing']]);
$groups = $groups->merge(new SenderCheckGroupCollection($checks->checkAnySender('Newsletter')));
$response = $groups->toResponseArray()['groups'];
$headings = array_column($response, 'heading');
$any = 'Any sender (used by Newsletter)';
$expected = ['*@good.test (used by Website, Billing)', 'me@single.test', '*@broken.test', $any];
$suite->check('there\'s a group per rule, naming the keys', $headings === $expected, json_encode($headings));
$levels = array_column($response, 'level');
$isWorst = ($levels === ['info', 'warning', 'error', 'warning']);
$suite->check('each group has its worst level', $isWorst, json_encode($levels));

$brokenSources = array_column($response[2]['checks'], 'source');
$isProviderStillChecked = in_array('sendgrid', $brokenSources, true) && in_array('mailcow', $brokenSources, true);
$name = 'a failed DNS lookup is an error, and the providers are still checked';
$suite->check($name, $isProviderStillChecked, json_encode($response[2]));

$anyGroups = $checks->checkAllowedSenders(new AnyAddress())->toResponseArray()['groups'];
$isAnyGroup = (array_column($anyGroups, 'heading') === ['Any sender']);
$suite->check('a token with no sender condition is checked for any sender', $isAnyGroup, json_encode($anyGroups));

$failed = new SenderChecks($dns, new FailedSenderChecker('sendgrid', 'SendGrid rejected the API key.'));
$failedAccount = $failed->checkAccounts()->toResponseArray()['groups'][0];
$isFailed = ($failedAccount['level'] === 'error');
$suite->check('a provider that couldn\'t be asked says why', $isFailed, json_encode($failedAccount));

$suite->finish();
