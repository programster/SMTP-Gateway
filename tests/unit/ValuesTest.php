<?php

/*
 * The value objects that keys' restrictions and rate limits are made of, parsing email addresses and headers, and
 * showing times in TIMEZONE.
 */

declare(strict_types = 1);

require_once __DIR__ . '/TestSuite.php';

$suite = new TestSuite('Values');

$parseAddresses = function (string $header) : string {
    $addresses = [];

    foreach (AddressListParser::parse($header) as $address)
    {
        $addresses[] = (string)$address;
    }

    return implode(',', $addresses);
};

// Parsing address headers.
$parsed = $parseAddresses('"Doe, Jane" <jane@x.org>, bob@y.org');
$suite->check('display name with a comma', $parsed === 'jane@x.org,bob@y.org', $parsed);
$suite->check('comment removed', $parseAddresses('ceo@bank.example (The CEO)') === 'ceo@bank.example');
$suite->check('group syntax', $parseAddresses('Team: a@x.org, b@x.org;') === 'a@x.org,b@x.org');
$suite->check('domain lowercased', $parseAddresses('A@EXAMPLE.ORG') === 'A@example.org');

$parseGarbage = function () : void {
    AddressListParser::parse('not an address');
};

$parseUnclosed = function () : void {
    AddressListParser::parse('Jane <jane@x.org');
};

$parseTwoInOne = function () : void {
    AddressListParser::parse('<a@x.org> <b@x.org>');
};

$suite->checkThrows('garbage refused', InvalidInputException::class, $parseGarbage);
$suite->checkThrows('unclosed angle bracket refused', InvalidInputException::class, $parseUnclosed);
$suite->checkThrows('two addresses in one mailbox refused', InvalidInputException::class, $parseTwoInOne);

// Networks.
$suite->check('IPv4 CIDR contains', (new IpNetwork('10.0.0.0/8'))->contains(new IpAddress('10.20.30.40')));
$suite->check('IPv4 CIDR excludes', !(new IpNetwork('10.0.0.0/8'))->contains(new IpAddress('11.0.0.1')));
$suite->check('IPv6 CIDR contains', (new IpNetwork('2001:db8::/32'))->contains(new IpAddress('2001:db8:1::5')));
$isMapped = (new IpNetwork('172.16.0.0/12'))->contains(new IpAddress('::ffff:172.18.0.1'));
$suite->check('mapped IPv4 address matches an IPv4 network', $isMapped);
$suite->check('IPv4 never in an IPv6 network', !(new IpNetwork('::/0'))->contains(new IpAddress('1.2.3.4')));
$suite->check('/0 contains everything', (new IpNetwork('0.0.0.0/0'))->contains(new IpAddress('8.8.8.8')));

$createWithHostBits = function () : void {
    new IpNetwork('192.168.1.5/24');
};

$createWithBadPrefix = function () : void {
    new IpNetwork('10.0.0.0/33');
};

$suite->checkThrows('host bits set refused', InvalidInputException::class, $createWithHostBits);
$suite->checkThrows('prefix too long refused', InvalidInputException::class, $createWithBadPrefix);

// Address rules.
$domainRule = AddressRule::createFromString('*@irap.org');
$suite->check('domain rule matches, in any case', $domainRule->allows(new EmailAddress('Bob@IRAP.org')));
$suite->check('domain rule excludes subdomains', !$domainRule->allows(new EmailAddress('bob@mail.irap.org')));
$suite->check('domain rule excludes lookalikes', !$domainRule->allows(new EmailAddress('bob@evilirap.org')));
$isAllowed = AddressRule::createFromString('Bob@irap.org')->allows(new EmailAddress('bob@IRAP.ORG'));
$suite->check('address rule is case-insensitive', $isAllowed);

$createStrayWildcard = function () : void {
    AddressRule::createFromString('bob*@irap.org');
};

$createEmptyRuleSet = function () : void {
    new AddressRuleSet();
};

$createEmptyAllowList = function () : void {
    new IpAllowList();
};

$createAnyoneRule = function () : void {
    AddressRule::createFromString('*');
};

$suite->checkThrows('stray wildcard refused', InvalidInputException::class, $createStrayWildcard);
$suite->checkThrows('a "*" rule is refused, as anyone is no rule', InvalidInputException::class, $createAnyoneRule);
$suite->checkThrows('empty rule set refused', InvalidInputException::class, $createEmptyRuleSet);
$suite->checkThrows('empty IP allow list refused', InvalidInputException::class, $createEmptyAllowList);
$isAnyone = (new AnyAddress())->allows(new EmailAddress('anyone@anywhere.example'));
$suite->check('no address condition allows anyone', $isAnyone && (new AnyAddress())->describe() === 'Anyone');
$isAnywhere = (new AnyIp())->allows(new IpAddress('203.0.113.9')) && (new AnyIp())->allows(new IpAddress('::1'));
$suite->check('no client IP condition allows anywhere', $isAnywhere);

// Accounts: only an active one can log in, and logs name them only by ID.
$canLogIn = (
       AccountStatus::ACTIVE->canLogIn()
    && !AccountStatus::BLOCKED->canLogIn()
    && !AccountStatus::ARCHIVED->canLogIn()
);

$suite->check('only an active account can log in', $canLogIn);
$isAccount = LogContextKey::isAccountId('user_id') && LogContextKey::isAccountId('by_user_id');
$suite->check('user_id and names ending _user_id are account IDs in the logs', $isAccount);
$suite->check('other names aren\'t', !LogContextKey::isAccountId('email') && !LogContextKey::isAccountId('user_ids'));

// Rate limits.
$description = (new RateLimit(RateLimitUnit::EMAILS, 300, 3600))->describe();
$suite->check('describes emails per hour', $description === '300 emails per hour', $description);
$description = (new RateLimit(RateLimitUnit::RECIPIENTS, 5, 7200))->describe();
$suite->check('describes recipients per 2 hours', $description === '5 recipients per 2 hours', $description);
$description = (new RateLimit(RateLimitUnit::RECIPIENTS, 50000, 2592000))->describe();
$suite->check('describes 30 days, with thousands', $description === '50,000 recipients per 30 days', $description);
$description = (new RateLimit(RateLimitUnit::EMAILS, 1, 604800))->describe();
$suite->check('describes a week, in the singular', $description === '1 email per week', $description);
$emailLimit = new RateLimit(RateLimitUnit::EMAILS, 1, 60);
$suite->check('email limits never block on recipients', $emailLimit->canEverAllow(500));
$recipientLimit = new RateLimit(RateLimitUnit::RECIPIENTS, 10, 60);
$isBlocked = !$recipientLimit->canEverAllow(11) && $recipientLimit->canEverAllow(10);
$suite->check('recipient limits block emails with more recipients', $isBlocked);
$limits = new RateLimitSet($emailLimit, new RateLimit(RateLimitUnit::RECIPIENTS, 1, 60));
$suite->check('the same period is allowed for different units', count($limits->getLimits()) === 2);

$createSameUnitAndPeriod = function () : void {
    new RateLimitSet(new RateLimit(RateLimitUnit::EMAILS, 1, 60), new RateLimit(RateLimitUnit::EMAILS, 2, 60));
};

$suite->checkThrows('the same unit and period refused', InvalidInputException::class, $createSameUnitAndPeriod);

// Raw email headers.
$raw = new RawEmail(
    "X-SMTPAPI: {\"to\":[\"evil@x.org\"]}\r\n x-continued\r\nFrom: a@b.org\r\nSubject: =?UTF-8?B?w6l0w6k=?=\r\n\r\n"
    . "body\r\n"
);

$cleaned = $raw->withoutHeader('x-smtpapi')->toString();
$isRemoved = !str_contains($cleaned, 'evil') && !str_contains($cleaned, 'continued');
$suite->check('a folded header is removed entirely', $isRemoved, $cleaned);
$suite->check('an encoded subject is decoded', $raw->getSubject() === 'été', $raw->getSubject());

// Times.
$shown = Time::format(gmmktime(12, 0, 0, 7, 1, 2026));
$isShown = ($shown === '13:00:00 01-07-2026');
$suite->check('times are shown in TIMEZONE (Europe/London), the time first, then the date', $isShown, $shown);
$time = new DateTimeImmutable('2026-07-01 13:00:00');
$us = new DateTimeFormat(DateOrder::MONTH_DAY_YEAR, DateSeparator::SLASH, TimePosition::AFTER_DATE);
$suite->check('a US date, with slashes, before the time', $us->format($time, '') === '07/01/2026 13:00:00');
$iso = new DateTimeFormat(DateOrder::YEAR_MONTH_DAY, DateSeparator::DOT, TimePosition::BEFORE_DATE);
$suite->check('an ISO date, with dots, after the time', $iso->format($time, '') === '13:00:00 2026.07.01');
$suite->check('with a fraction of a second', $iso->format($time, '1234') === '13:00:00.1234 2026.07.01');

$suite->finish();
