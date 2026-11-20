<?php

/*
 * Restricting a provider to particular senders: reading "allowed_senders" (a list of domains, or of addresses, taken
 * as one OR the other), checking an email's envelope sender and From and Sender addresses against it, choosing only
 * providers that take an email, telling when none ever would, the provider form's two lists, and the sender checks.
 */

declare(strict_types = 1);

require_once __DIR__ . '/TestSuite.php';

$suite = new TestSuite('Provider senders');

$entry = function (string $name, ?array $allowedSenders = null, bool $enabled = true) : array {
    $data = [
        'id' => Uuid::generateV4(),
        'name' => $name,
        'type' => 'custom',
        'enabled' => $enabled,
        'host' => 'mailpit',
        'port' => 1025,
        'security' => 'none',
        'username' => '',
        'password' => '',
    ];

    return ($allowedSenders === null) ? $data : $data + ['allowed_senders' => $allowedSenders];
};

$parse = function (ProviderMode $mode, array ...$entries) : ProvidersConfig {
    $data = ['mode' => $mode->value, 'providers' => $entries];
    return ProvidersConfigParser::parse($data, new ProviderDefinitionCollection());
};

$addresses = function (string ...$addresses) : EmailAddressCollection {
    $collection = [];

    foreach ($addresses as $address)
    {
        $collection[] = new EmailAddress($address);
    }

    return new EmailAddressCollection(...$collection);
};

// Your example: the domain programster.org, or the address stuart@stuart.page.
$example = ['domains' => ['programster.org'], 'addresses' => ['stuart@stuart.page']];
$provider = $parse(ProviderMode::OVERFLOW, $entry('restricted', $example))->getProviders()[0];
$suite->check('anyone at a domain is taken', $provider->takesAll($addresses('anyone@programster.org')));
$suite->check('and so is an address', $provider->takesAll($addresses('stuart@stuart.page')));
$suite->check('the address is matched ignoring case', $provider->takesAll($addresses('Stuart@Stuart.Page')));
$suite->check('but not others at the address\'s domain', !$provider->takesAll($addresses('someone@stuart.page')));
$suite->check('nor the domain\'s subdomains', !$provider->takesAll($addresses('a@mail.programster.org')));
$suite->check('nor anyone else', !$provider->takesAll($addresses('a@example.org')));
$isAll = $provider->takesAll($addresses('a@programster.org', 'stuart@stuart.page'));
$suite->check('several addresses are taken when each one is', $isAll);
$isAll = $provider->takesAll($addresses('a@programster.org', 'a@example.org'));
$suite->check('but not if any one isn\'t (e.g. a From header unlike the envelope sender)', !$isAll);
$unrestricted = $parse(ProviderMode::OVERFLOW, $entry('any'))->getProviders()[0];
$suite->check('a provider without allowed_senders takes anyone', $unrestricted->takesAll($addresses('a@example.org')));

// Reading and writing it.
$domainsOnly = $parse(ProviderMode::OVERFLOW, $entry('d', ['domains' => ['programster.org']]))->getProviders()[0];
$suite->check('domains alone can be given', $domainsOnly->takesAll($addresses('x@programster.org')));
$onlyAddresses = ['addresses' => ['stuart@stuart.page']];
$addressesOnly = $parse(ProviderMode::OVERFLOW, $entry('a', $onlyAddresses))->getProviders()[0];
$suite->check('and so can addresses alone', $addressesOnly->takesAll($addresses('stuart@stuart.page')));
$config = $parse(ProviderMode::OVERFLOW, $entry('restricted', $example), $entry('any'));
$written = ProvidersConfigParser::toArray($config, SecretOutput::AS_IS)['providers'];
$isWritten = ($written[0]['allowed_senders'] ?? null) === $example;
$suite->check('it\'s written out as the two lists', $isWritten, json_encode($written[0]));
$suite->check('and left out for a provider that takes anyone', !isset($written[1]['allowed_senders']));
$yaml = ProvidersConfigParser::toYaml($config, SecretOutput::AS_IS);
$reread = SettingsFile::decodeYaml($yaml);
$isReread = ($reread['providers'][0]['allowed_senders'] ?? null) === $example;
$suite->check('and its YAML reads back the same', $isReread, $yaml);

$refusals = [
    'both lists empty' => [['domains' => [], 'addresses' => []], 'leave "allowed_senders" out'],
    'an empty mapping' => [[], 'leave "allowed_senders" out'],
    'a plain list' => [['programster.org'], 'must have a list of "domains"'],
    'a misspelt key' => [['domain' => ['programster.org']], 'unknown settings: domain'],
    'a domain that isn\'t one' => [['domains' => ['not a domain']], 'allowed_senders.domains[0]'],
    'an address that isn\'t one' => [['addresses' => ['nobody']], 'allowed_senders.addresses[0]'],
    'a list that isn\'t a list' => [['domains' => 'programster.org'], '"allowed_senders.domains" must be a list'],
];

foreach ($refusals as $description => [$allowedSenders, $expected])
{
    try
    {
        $parse(ProviderMode::OVERFLOW, $entry('bad', $allowedSenders));
        $message = 'nothing was thrown';
    }
    catch (InvalidInputException $e)
    {
        $message = $e->getMessage();
    }

    $suite->check("{$description} is refused, saying where", str_contains($message, $expected), $message);
}

// Choosing among the providers that take an email, in each mode.
$restrictedFirst = $parse(
    ProviderMode::OVERFLOW,
    $entry('sender-first', ['domains' => ['programster.org']]),
    $entry('sender-second')
);

$from = function (string $address) use ($addresses) : ProviderEligibility {
    return new ProviderEligibility(new AnyProvider(), $addresses($address));
};

foreach (ProviderMode::cases() as $mode)
{
    $modeConfig = $parse(
        $mode,
        $entry('sender-first', ['domains' => ['programster.org']]),
        $entry('sender-second', ['addresses' => ['stuart@stuart.page']])
    );

    $chosen = ProviderSelector::choose($modeConfig, $from('a@programster.org'), 1, [], []);
    $suite->check("{$mode->value}: a sender only the first takes goes through it", $chosen === 'sender-first', $chosen);
    $chosen = ProviderSelector::choose($modeConfig, $from('stuart@stuart.page'), 1, [], []);
    $isSecond = ($chosen === 'sender-second');
    $suite->check("{$mode->value}: one only the second takes goes through that", $isSecond, $chosen);
    $chosen = ProviderSelector::choose($modeConfig, $from('a@example.org'), 1, [], []);
    $suite->check("{$mode->value}: one neither takes goes through neither", $chosen === '', $chosen);
}

$chosen = ProviderSelector::choose($restrictedFirst, $from('a@example.org'), 1, [], []);
$suite->check('overflow: a sender the first doesn\'t take goes to the next', $chosen === 'sender-second', $chosen);

// Telling when no provider would ever take an email.
$noneTake = $parse(ProviderMode::OVERFLOW, $entry('only-programster', ['domains' => ['programster.org']]));
$isRefused = $from('a@example.org')->isRefusedByEveryProvider($noneTake);
$suite->check('an email no provider takes is refused', $isRefused);
$described = $from('a@example.org')->describeSendersNotTaken($noneTake);
$suite->check('naming the sender that isn\'t taken', $described === 'a@example.org', $described);
$suite->check('one some provider takes isn\'t', !$from('a@programster.org')->isRefusedByEveryProvider($noneTake));

$disabledTakes = $parse(
    ProviderMode::OVERFLOW,
    $entry('enabled-other', ['domains' => ['example.com']]),
    $entry('disabled-takes', ['domains' => ['programster.org']], false)
);

$isRefused = $from('a@programster.org')->isRefusedByEveryProvider($disabledTakes);
$suite->check('nor is one only a disabled provider takes, as it waits for it', !$isRefused);
$noProviders = new ProviderEligibility(new NoRemainingProviders(), $addresses('a@example.org'));
$isRefused = $noProviders->isRefusedByEveryProvider($noneTake);
$suite->check('nor is one whose token has no providers left, as that waits too', !$isRefused);
$ids = $noneTake->getIds();
$tokenOnly = new ProviderEligibility(new ProviderAllowList(...$ids->toArray()), $addresses('a@programster.org'));
$suite->check('a token\'s own provider that takes it is enough', !$tokenOnly->isRefusedByEveryProvider($noneTake));

// An email's senders: its envelope sender, and the From and Sender addresses, each once.
$raw = new RawEmail("From: A <a@programster.org>\r\nSender: s@programster.org\r\nSubject: x\r\n\r\nbody\r\n");
$senders = [];

foreach ($raw->getSenderAddresses(new EmailAddress('A@programster.org')) as $sender)
{
    $senders[] = (string)$sender;
}

$isEach = ($senders === ['A@programster.org', 's@programster.org']);
$suite->check('an email\'s senders: its envelope sender, From and Sender, once each', $isEach, json_encode($senders));

// Rules overlapping, for the sender checks.
$overlaps = [
    ['*@programster.org', '*@programster.org', true],
    ['*@programster.org', '*@example.org', false],
    ['*@programster.org', 'a@programster.org', true],
    ['a@programster.org', '*@programster.org', true],
    ['a@programster.org', 'b@programster.org', false],
    ['a@programster.org', 'A@programster.org', true],
];

foreach ($overlaps as [$first, $second, $expected])
{
    $result = AddressRule::createFromString($first)->overlaps(AddressRule::createFromString($second));
    $suite->check("{$first} and {$second} " . ($expected ? 'overlap' : 'don\'t overlap'), $result === $expected);
}

// The sender checks only check a provider for the senders it takes.
$takes = AddressRuleSet::createFromText('*@programster.org');
$checker = new SenderRestrictedChecker(new FailedSenderChecker('restricted', 'unreachable'), $takes);
$levelOf = function (SenderCheckCollection $checks) : string {
    return $checks->toArray()[0]->getLevel()->value;
};

$taken = $checker->checkDomain(DomainDns::createUnknown('programster.org'));
$suite->check('a sender it takes is checked as usual', $levelOf($taken) === 'error', $levelOf($taken));
$notTaken = $checker->checkDomain(DomainDns::createUnknown('example.org'));
$suite->check('one it doesn\'t take is only noted, not a problem', $levelOf($notTaken) === 'info', $levelOf($notTaken));
$address = new EmailAddress('a@example.org');
$notTaken = $checker->checkAddress($address, DomainDns::createUnknown('example.org'));
$suite->check('as is an address it doesn\'t take', $levelOf($notTaken) === 'info', $levelOf($notTaken));

// The provider form's two lists.
$form = ['name' => 'form-test', 'enabled' => '1'];

foreach (ProviderFormFields::getForType(ProviderType::CUSTOM) as $field)
{
    $form[ProviderFormInput::getInputName(ProviderType::CUSTOM, $field)] = match ($field->getKind()) {
        ProviderFieldKind::SECRET => 'secret',
        ProviderFieldKind::TEXT => $field->getDefault() ?: 'mailpit',
        default => $field->getDefault(),
    };
}

$form[ProviderSenderRows::DOMAINS_PREFIX . '_0'] = '*@programster.org';
$form[ProviderSenderRows::DOMAINS_PREFIX . '_1'] = 'example.com';
$form[ProviderSenderRows::ADDRESSES_PREFIX . '_0'] = 'stuart@stuart.page';

$readForm = function (array $form) : array {
    $getField = function (string $name) use ($form) : string {
        return (string)($form[$name] ?? '');
    };

    return ProviderFormInput::readEntry($getField, ProviderType::CUSTOM, new ConfigSettingCollection());
};

$read = $readForm($form)['allowed_senders'] ?? null;
$expected = ['domains' => ['programster.org', 'example.com'], 'addresses' => ['stuart@stuart.page']];
$suite->check('the form\'s lists are read, with a domain\'s "*@" dropped', $read === $expected, json_encode($read));
$empty = $readForm(array_diff_key($form, array_flip(preg_grep('/^allowed_sender_/', array_keys($form)))));
$suite->check('both left empty take any sender', !isset($empty['allowed_senders']), json_encode($empty));

$readBadDomain = function () use ($readForm, $form) : void {
    $readForm([ProviderSenderRows::DOMAINS_PREFIX . '_0' => 'not a domain'] + $form);
};

$suite->checkThrows('a row that isn\'t a domain is refused', InvalidInputException::class, $readBadDomain);
$rows = ProviderSenderRows::createUsingRestriction($provider->getAllowedSenders());
$isRefilled = ($rows->getDomains() === ['programster.org'] && $rows->getAddresses() === ['stuart@stuart.page']);
$suite->check('and filled in again from a saved provider', $isRefilled);

$suite->finish();
