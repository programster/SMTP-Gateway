<?php

/*
 * The "custom" provider's sender checks: the SPF evaluator, the server's reverse DNS, and each sender's SPF and DKIM
 * records, all against made-up DNS.
 */

declare(strict_types = 1);

require_once __DIR__ . '/TestSuite.php';
require_once __DIR__ . '/Fakes.php';


$suite = new TestSuite('Custom provider');

$dns = new FakeDnsResolver(
    [
        'ip.test' => ['v=spf1 ip4:203.0.113.0/24 ip6:2001:db8::/32 -all'],
        'half.test' => ['v=spf1 ip4:203.0.113.0/25 -all'],
        'a.test' => ['v=spf1 a:mail.example.org -all'],
        'mx.test' => ['v=spf1 mx ~all'],
        'include.test' => ['v=spf1 include:spf.provider.test -all'],
        'spf.provider.test' => ['v=spf1 ip4:198.51.100.7 ?all'],
        'redirect.test' => ['v=spf1 redirect=ip.test'],
        'soft.test' => ['v=spf1 ip4:192.0.2.1 ~all'],
        'neutral.test' => ['v=spf1 ip4:192.0.2.1'],
        'macro.test' => ['v=spf1 exists:%{i}._spf.provider.test -all'],
        'badinclude.test' => ['v=spf1 include:nothing.test -all'],
        'typo.test' => ['v=spf1 ip4 -all'],
        'loop.test' => ['v=spf1 include:loop.test -all'],
        'dkim._domainkey.ip.test' => ['v=DKIM1; k=rsa; p=MIIBIjANBgkqh'],
        'dkim._domainkey.soft.test' => ['v=DKIM1; p='],
        '_dmarc.reject.test' => ['v=DMARC1; p=reject'],
        'reject.test' => ['v=spf1 -all'],
    ],
    [],
    ['timeout.test'],
    [
        'mail.example.org' => ['203.0.113.10', '2001:db8::10'],
        'mx1.mx.test' => ['203.0.113.10'],
        'rdns.example.net' => ['203.0.113.99'],
        'private.example.org' => ['10.0.0.5'],
        'unmatched.example.org' => ['198.51.100.20'],
    ],
    ['mx.test' => ['mx1.mx.test']],
    [
        '203.0.113.10' => ['mail.example.org'],
        '2001:db8::10' => ['mail.example.org'],
        '198.51.100.20' => ['rdns.example.net'],
    ]
);

// --- SPF

$spf = new SpfEvaluator($dns);

$cases = [
    ['ip.test', '203.0.113.10', SpfResult::PASS, 'an ip4 range'],
    ['ip.test', '2001:db8::10', SpfResult::PASS, 'an ip6 range'],
    ['ip.test', '198.51.100.1', SpfResult::FAIL, 'an address outside every range, with -all'],
    ['half.test', '203.0.113.100', SpfResult::PASS, 'an address in a /25'],
    ['half.test', '203.0.113.200', SpfResult::FAIL, 'an address just outside a /25'],
    ['a.test', '203.0.113.10', SpfResult::PASS, 'an a: mechanism'],
    ['mx.test', '203.0.113.10', SpfResult::PASS, 'an mx mechanism'],
    ['mx.test', '192.0.2.200', SpfResult::SOFTFAIL, '~all'],
    ['include.test', '198.51.100.7', SpfResult::PASS, 'an include that passes'],
    ['include.test', '192.0.2.200', SpfResult::FAIL, 'an include that is neutral, falling through to -all'],
    ['redirect.test', '203.0.113.10', SpfResult::PASS, 'a redirect'],
    ['neutral.test', '192.0.2.200', SpfResult::NEUTRAL, 'no match and no all'],
    ['none.test', '192.0.2.200', SpfResult::NONE, 'no record'],
    ['macro.test', '192.0.2.200', SpfResult::UNKNOWN, 'a macro'],
    ['badinclude.test', '192.0.2.200', SpfResult::PERMERROR, 'an include of a domain with no record'],
    ['typo.test', '192.0.2.200', SpfResult::PERMERROR, 'ip4 with no address'],
    ['loop.test', '192.0.2.200', SpfResult::PERMERROR, 'an include loop, over the lookup limit'],
    ['timeout.test', '192.0.2.200', SpfResult::TEMPERROR, 'a failed lookup'],
];

foreach ($cases as [$domain, $ip, $expected, $name])
{
    $result = $spf->evaluate($domain, $ip);
    $suite->check("SPF: {$name} is {$expected->value}", $result === $expected, $result->value);
}

$evaluateHostname = function () use ($spf) : void {
    $spf->evaluate('ip.test', 'mail.example.org');
};

$suite->checkThrows('SPF: a non-address is rejected', InvalidInputException::class, $evaluateHostname);

// --- The server

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

$mailcow = CustomSenderChecker::fetch('mailcow', $dns, 'mail.example.org', 'dkim');
$account = $describe($mailcow->checkAccount());
$expected = 'ok: 203.0.113.10 has matching reverse DNS | ok: 2001:db8::10 has matching reverse DNS';
$suite->check('a server with matching reverse DNS passes', $account === $expected, $account);

$unmatched = $describe(CustomSenderChecker::fetch('x', $dns, 'unmatched.example.org', '')->checkAccount());
$isUnmatched = str_starts_with($unmatched, 'warning: 198.51.100.20\'s reverse DNS doesn\'t match');
$suite->check('reverse DNS that doesn\'t resolve back is a warning', $isUnmatched, $unmatched);
$isDkimNoted = str_contains($unmatched, 'info: DKIM keys aren\'t checked');
$suite->check('and DKIM is noted as unchecked', $isDkimNoted, $unmatched);

$missing = $describe(CustomSenderChecker::fetch('x', $dns, '203.0.113.99', 'dkim')->checkAccount());
$suite->check('no reverse DNS is a warning', $missing === 'warning: 203.0.113.99 has no reverse DNS', $missing);

$private = CustomSenderChecker::fetch('x', $dns, 'private.example.org', 'dkim');
$privateAccount = $describe($private->checkAccount());
$isPrivate = ($privateAccount === 'info: private.example.org is on a private network');
$suite->check('a private server is only noted', $isPrivate, $privateAccount);
$privateDomain = $describe($private->checkDomain(DomainDns::fetch($dns, 'ip.test')));
$isUnchecked = str_starts_with($privateDomain, 'info: Can\'t check ip.test\'s SPF record');
$suite->check('and its senders\' SPF isn\'t claimed to fail', $isUnchecked, $privateDomain);

$nowhere = $describe(CustomSenderChecker::fetch('x', $dns, 'nowhere.example.org', '')->checkAccount());
$isNowhere = str_starts_with($nowhere, 'error: nowhere.example.org doesn\'t resolve');
$suite->check('a server that doesn\'t resolve is an error', $isNowhere, $nowhere);

// --- Senders

$good = $describe($mailcow->checkDomain(DomainDns::fetch($dns, 'ip.test')));

$expected =
    'ok: SPF lets mail.example.org send as ip.test | ok: SPF lets mail.example.org send as ip.test'
    . ' | ok: ip.test publishes a DKIM key';

$suite->check('a domain that allows the server and has its key passes', $good === $expected, $good);

$soft = $describe($mailcow->checkAddress(new EmailAddress('me@soft.test'), DomainDns::fetch($dns, 'soft.test')));

$isSoft = (
       str_contains($soft, 'warning: SPF doesn\'t list mail.example.org (203.0.113.10)')
    && str_contains($soft, 'error: The DKIM key has been revoked')
);

$suite->check('~all is a warning, and an empty DKIM key an error', $isSoft, $soft);

$reject = $describe($mailcow->checkDomain(DomainDns::fetch($dns, 'reject.test')));

$isRejected = (
       str_contains($reject, 'error: SPF refuses mail.example.org')
    && str_contains($reject, 'warning: No DKIM key for reject.test')
);

$suite->check('SPF refusing the server is an error under p=reject', $isRejected, $reject);

$macro = $describe($mailcow->checkDomain(DomainDns::fetch($dns, 'macro.test')));
$isMacro = str_starts_with($macro, 'info: Couldn\'t work out macro.test\'s SPF record');
$suite->check('a macro is only noted', $isMacro, $macro);

// --- Settings

$selector = function (string $selector) : void {
    $host = ConfigValue::createFromString('mail.example.org');
    $empty = ConfigValue::createFromString('');
    new SmtpSettings($host, 587, SmtpSecurity::TLS, $empty, $empty, $selector);
};

$trailingDot = function () use ($selector) : void {
    $selector('dkim.');
};

$suite->checkThrows('a selector with a trailing dot is rejected', InvalidInputException::class, $trailingDot);

$config = ProvidersConfigParser::parse(
    [
        'mode' => 'overflow',
        'providers' => [
            [
                'name' => 'mailcow', 'type' => 'custom', 'host' => 'mail.example.org', 'port' => 587,
                'security' => 'tls', 'username' => '', 'password' => '', 'dkim_selector' => 'dkim',
            ],
        ],
    ],
    new ProviderDefinitionCollection()
);

$yaml = ProvidersConfigParser::toYaml($config, SecretOutput::AS_IS);
$isWritten = (str_contains($yaml, 'type: "custom"') && str_contains($yaml, 'dkim_selector: "dkim"'));
$suite->check('a custom provider and its selector are read and written', $isWritten, $yaml);

$parseOldType = function () : void {
    $provider = ['name' => 'x', 'type' => 'smtp', 'host' => 'h', 'port' => 25, 'security' => 'none'];
    $data = ['mode' => 'overflow', 'providers' => [$provider]];
    ProvidersConfigParser::parse($data, new ProviderDefinitionCollection());
};

$suite->checkThrows('the old "smtp" type is rejected', InvalidInputException::class, $parseOldType);

$suite->finish();
