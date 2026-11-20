<?php

/*
 * Restricting an email token to particular providers: the providers' ids (and keeping them when they're left out), the
 * restriction types, storing them with a token, reading them from the API and the form, choosing a provider from a
 * token's own (in each mode), and what a provider's removal does to the tokens linked to it.
 */

declare(strict_types = 1);

require_once __DIR__ . '/TestSuite.php';

$suite = new TestSuite('Provider restriction');

// The ids of the test stack's two providers, in tests/settings.yaml.
$primaryId = new ProviderId('5c2f0e7a-1d3b-4a6c-9e8f-0a1b2c3d4e01');
$secondaryId = new ProviderId('5c2f0e7a-1d3b-4a6c-9e8f-0a1b2c3d4e02');

// Provider ids.
$suite->check('a new id is a UUID', Uuid::isValid(ProviderId::createNew()->getValue()));
$suite->check('and each is different', !ProviderId::createNew()->equals(ProviderId::createNew()));
$upper = new ProviderId('5C2F0E7A-1D3B-4A6C-9E8F-0A1B2C3D4E01');
$suite->check('an id is kept in lowercase, so it equals the same id', $upper->equals($primaryId), (string)$upper);

$createNotUuid = function () : void {
    new ProviderId('mailpit');
};

$suite->checkThrows('a name isn\'t an id', InvalidInputException::class, $createNotUuid);

// Reading providers, with and without ids.
$entry = function (string $name, ?string $id = null, bool $enabled = true) : array {
    $data = [
        'name' => $name,
        'type' => 'custom',
        'enabled' => $enabled,
        'host' => 'mailpit',
        'port' => 1025,
        'security' => 'none',
        'username' => '',
        'password' => '',
    ];

    return ($id === null) ? $data : ['id' => $id] + $data;
};

$parse = function (ProviderMode $mode, ProviderDefinitionCollection $current, array ...$entries) : ProvidersConfig {
    return ProvidersConfigParser::parse(['mode' => $mode->value, 'providers' => $entries], $current);
};

$none = new ProviderDefinitionCollection();
$given = $parse(ProviderMode::OVERFLOW, $none, $entry('a', (string)$primaryId), $entry('b'));
$suite->check('an id given is kept', $given->getProviders()[0]->getId()->equals($primaryId));
$suite->check('one left out is made', Uuid::isValid($given->getProviders()[1]->getId()->getValue()));
$again = $parse(ProviderMode::OVERFLOW, $given->getProviders(), $entry('a'), $entry('b'), $entry('c'));
$isKept = $again->getProviders()[1]->getId()->equals($given->getProviders()[1]->getId());
$suite->check('one left out keeps the current id of the provider with its name', $isKept);
$suite->check('even when the other was given one', $again->getProviders()[0]->getId()->equals($primaryId));
$isNew = !$given->getIds()->includes($again->getProviders()[2]->getId());
$suite->check('a new name gets a new id', $isNew && $again->getProviders()[2]->getName() === 'c');
$asArray = ProvidersConfigParser::toArray($again, SecretOutput::MASKED);
$suite->check('the id is written out first', array_key_first($asArray['providers'][0]) === 'id');

$parseDuplicateIds = function () use ($parse, $entry, $none, $primaryId) : void {
    $parse(ProviderMode::OVERFLOW, $none, $entry('a', (string)$primaryId), $entry('b', (string)$primaryId));
};

$suite->checkThrows('two providers with the same id are refused', InvalidInputException::class, $parseDuplicateIds);

$parseBadId = function () use ($parse, $entry, $none) : void {
    $parse(ProviderMode::OVERFLOW, $none, $entry('a', 'not-a-uuid'));
};

$suite->checkThrows('an id that isn\'t a UUID is refused', InvalidInputException::class, $parseBadId);

// The restrictions, keeping the providers' order.
$ids = [ProviderId::createNew(), ProviderId::createNew(), ProviderId::createNew()];

$config = $parse(
    ProviderMode::OVERFLOW,
    $none,
    $entry('first', (string)$ids[0]),
    $entry('second', (string)$ids[1]),
    $entry('third', (string)$ids[2])
);

$names = function (ProviderDefinitionCollection $providers) : string {
    $found = [];

    foreach ($providers as $provider)
    {
        /* @var $provider ProviderDefinition */
        $found[] = $provider->getName();
    }

    return implode(',', $found);
};

$filtered = $names((new ProviderAllowList($ids[2], $ids[0]))->filter($config->getProviders()));
$suite->check('an allow list keeps the providers\' own order', $filtered === 'first,third', $filtered);
$filtered = $names((new AnyProvider())->filter($config->getProviders()));
$suite->check('any provider keeps them all', $filtered === 'first,second,third', $filtered);
$filtered = $names((new NoRemainingProviders())->filter($config->getProviders()));
$suite->check('no remaining providers keeps none', $filtered === '', $filtered);

$createEmptyList = function () : void {
    new ProviderAllowList();
};

$suite->checkThrows('an empty allow list is refused', InvalidInputException::class, $createEmptyList);
$deduplicated = new ProviderAllowList($ids[0], new ProviderId(strtoupper((string)$ids[0])));
$suite->check('a provider given twice is kept once', count($deduplicated->getIds()) === 1);

// Choosing a provider from a token's own, in each mode. None of these providers has sent anything, or has limits, or
// is restricted to particular senders.
$fromAnyone = function (ProviderRestrictionInterface $allowed) : ProviderEligibility {
    return new ProviderEligibility($allowed, new EmailAddressCollection(new EmailAddress('svc@example.org')));
};

$choose = function (ProviderMode $mode, ProviderRestrictionInterface $allowed, array $tried = [], array $reserved = [])
    use ($parse, $entry, $none, $ids, $fromAnyone) : string {
    $modeConfig = $parse(
        $mode,
        $none,
        $entry('sel-first', (string)$ids[0]),
        $entry('sel-second', (string)$ids[1]),
        $entry('sel-third', (string)$ids[2])
    );

    return ProviderSelector::choose($modeConfig, $fromAnyone($allowed), 1, $tried, $reserved);
};

$onlySecond = new ProviderAllowList($ids[1]);
$secondAndThird = new ProviderAllowList($ids[1], $ids[2]);

foreach (ProviderMode::cases() as $mode)
{
    $chosen = $choose($mode, $onlySecond);
    $suite->check("{$mode->value}: a token with one provider always uses it", $chosen === 'sel-second', $chosen);
    $chosen = $choose($mode, $secondAndThird);
    $suite->check("{$mode->value}: with two, the first of its own comes first", $chosen === 'sel-second', $chosen);
    $chosen = $choose($mode, new NoRemainingProviders());
    $suite->check("{$mode->value}: with none, nothing is chosen", $chosen === '', $chosen);
}

$chosen = $choose(ProviderMode::OVERFLOW, $secondAndThird, ['sel-second']);
$suite->check('overflow: once its first is tried, its next is used', $chosen === 'sel-third', $chosen);
$chosen = $choose(ProviderMode::OVERFLOW, $onlySecond, ['sel-second']);
$suite->check('but never one outside its own', $chosen === '', $chosen);
$chosen = $choose(ProviderMode::OVERFLOW, $secondAndThird, [], ['sel-second']);
$suite->check('overflow: a provider a higher priority email reserved is passed over', $chosen === 'sel-third', $chosen);
$chosen = $choose(ProviderMode::FALLBACK, $secondAndThird, [], ['sel-second']);
$suite->check('fallback: a reserved first provider is waited for, not skipped', $chosen === '', $chosen);

$fallbackConfig = $parse(
    ProviderMode::FALLBACK,
    $none,
    $entry('sel-first', (string)$ids[0]),
    $entry('sel-second', (string)$ids[1]),
    $entry('sel-third', (string)$ids[2])
);

$usable = ProviderSelector::getUsableNow($fallbackConfig, $fromAnyone($secondAndThird), []);
$usableNames = $names($usable);
$suite->check('fallback: an email only waits for its first provider', $usableNames === 'sel-second', $usableNames);
$noProviders = $fromAnyone(new NoRemainingProviders());
$wait = ProviderSelector::getSecondsUntilAProviderHasRoom($fallbackConfig, $noProviders, 1, QueuedDemand::none());
$suite->check('with no providers, it would wait until something changes', $wait === INF, (string)$wait);

$disabledConfig = $parse(
    ProviderMode::OVERFLOW,
    $none,
    $entry('sel-first', (string)$ids[0]),
    $entry('sel-second', (string)$ids[1], false)
);

$chosen = ProviderSelector::choose($disabledConfig, $fromAnyone($onlySecond), 1, [], []);
$suite->check('a token whose only provider is disabled can\'t send', $chosen === '', $chosen);

// Storing it with a token, and reading it back.
$createPolicy = function (ProviderRestrictionInterface $providers) : EmailTokenPolicy {
    return new EmailTokenPolicy(
        new AnyIp(),
        new AnyAddress(),
        new AnyAddress(),
        new AnyAddress(),
        $providers,
        new RateLimitSet(),
        Priority::forEmailToken(1)
    );
};

$unrestricted = EmailTokenRepository::create('provider test: any', $createPolicy(new AnyProvider()))->getEmailToken();
$loaded = EmailTokenRepository::loadPolicy($unrestricted)->getAllowedProviders();
$suite->check('an unrestricted token loads as any provider', $loaded instanceof AnyProvider, $loaded::class);

$secondaryOnly = $createPolicy(new ProviderAllowList($secondaryId));
$restricted = EmailTokenRepository::create('provider test: secondary', $secondaryOnly)->getEmailToken();
$loaded = EmailTokenRepository::loadProviderRestriction((string)$restricted->getId());
$isSecondary = ($loaded instanceof ProviderAllowList && $loaded->allows($secondaryId) && !$loaded->allows($primaryId));
$suite->check('a restricted token loads its providers', $isSecondary, $loaded::class);

$both = new ProviderAllowList($primaryId, $secondaryId);
$bothToken = EmailTokenRepository::create('provider test: both', $createPolicy($both))->getEmailToken();
EmailTokenRepository::update($bothToken, 'provider test: both', $createPolicy(new ProviderAllowList($primaryId)));
$loaded = EmailTokenRepository::loadProviderRestriction((string)$bothToken->getId());

$isPrimaryOnly = (
       $loaded instanceof ProviderAllowList
    && count($loaded->getIds()) === 1
    && $loaded->allows($primaryId)
);

$suite->check('changing a token replaces its providers', $isPrimaryOnly, $loaded::class);

// A provider's removal.
$links = EmailTokenRepository::loadProviderLinks();
$stackConfig = ProviderRegistry::getConfig();
$linkedToSecondary = $links->filterLinkedTo($secondaryId)->describeNames();
$isFound = str_contains($linkedToSecondary, 'provider test: secondary');
$suite->check('the tokens linked to a provider are found', $isFound, $linkedToSecondary);
$suite->check('and only those', !str_contains($linkedToSecondary, 'provider test: both'), $linkedToSecondary);
$stranded = $links->filterStrandedWithout($secondaryId, $stackConfig)->describeNames();
$isStranded = str_contains($stranded, 'provider test: secondary');
$suite->check('a token with only that provider would be left with none', $isStranded, $stranded);
$secondary = $stackConfig->getProviderById($secondaryId);
$notice = ProviderRemovalNotice::createForProvider($secondary, $stackConfig, $links)->getMessage();
$isNamed = str_contains($notice, '"provider test: secondary"');
$suite->check('the removal notice names the tokens linked to it', $isNamed, $notice);
$isWarned = str_contains($notice, 'no enabled provider left');
$suite->check('and says which would have no enabled provider left', $isWarned, $notice);

$unlinked = EmailTokenRepository::removeLinksToOtherProviders(new ProviderIdCollection($primaryId));
$found = [];

foreach ($unlinked as $emailToken)
{
    /* @var $emailToken EmailToken */
    $found[] = (string)$emailToken->getId();
}

$isReported = in_array((string)$restricted->getId(), $found, true);
$suite->check('removing a provider\'s links says which tokens lost one', $isReported, implode(',', $found));
$loaded = EmailTokenRepository::loadProviderRestriction((string)$restricted->getId());
$isNone = ($loaded instanceof NoRemainingProviders);
$suite->check('a token that lost its last provider stays restricted, to none', $isNone, $loaded::class);
$warning = (new ProviderRestrictionSummary($loaded, $stackConfig->getProviders()))->getWarning();
$suite->check('and its page warns that its emails will wait', str_contains($warning, 'removed'), $warning);
$summary = new ProviderRestrictionSummary(new AnyProvider(), $stackConfig->getProviders());
$isPlain = ($summary->getWarning() === '' && $summary->getDescription() === 'Any provider');
$suite->check('an unrestricted token has no warning', $isPlain, $summary->getWarning());

// Reading it from the API, and the form.
$readJson = function (array $allowed) : ProviderRestrictionInterface {
    $body = ['name' => 'x', 'conditions' => [['field' => 'provider', 'allowed' => $allowed]]];
    return PolicyInput::readPolicyFromJson($body)->getAllowedProviders();
};

$read = $readJson([(string)$secondaryId]);
$isRead = ($read instanceof ProviderAllowList && $read->allows($secondaryId));
$suite->check('the API takes a provider condition by id', $isRead, $read::class);

$readUnknown = function () use ($readJson) : void {
    $readJson([(string)ProviderId::createNew()]);
};

$suite->checkThrows('an id that isn\'t a provider\'s is refused', InvalidInputException::class, $readUnknown);

$readName = function () use ($readJson) : void {
    $readJson(['mailpit']);
};

$suite->checkThrows('so is a name', InvalidInputException::class, $readName);

$readEmpty = function () use ($readJson) : void {
    $readJson([]);
};

$suite->checkThrows('and an empty list', InvalidInputException::class, $readEmpty);

$current = $createPolicy(new ProviderAllowList($primaryId));
$patched = PolicyInput::readPolicyPatchFromJson(['priority' => 2], $current)->getAllowedProviders();
$isKept = ($patched instanceof ProviderAllowList && $patched->allows($primaryId));
$suite->check('a PATCH without it keeps the providers', $isKept, $patched::class);
$removal = ['conditions' => [['field' => 'provider', 'allowed' => null]]];
$patched = PolicyInput::readPolicyPatchFromJson($removal, $current)->getAllowedProviders();
$suite->check('and one with "allowed": null removes the restriction', $patched instanceof AnyProvider, $patched::class);

$readForm = function (array $form) : ProviderRestrictionInterface {
    $getField = function (string $name) use ($form) : string {
        return (string)($form[$name] ?? '');
    };

    return PolicyInput::readPolicyFromForm($getField)->getAllowedProviders();
};

$form = ['name' => 'x', 'priority' => '1'];
$suite->check('the form without the toggle allows any provider', $readForm($form) instanceof AnyProvider);
$ticked = $form + ['restrict_providers' => '1', "allowed_provider_{$secondaryId}" => '1'];
$read = $readForm($ticked);
$isTicked = ($read instanceof ProviderAllowList && $read->allows($secondaryId) && !$read->allows($primaryId));
$suite->check('with it, the ticked providers', $isTicked, $read::class);
$values = EmailTokenFormValues::createFromToken($restricted, $createPolicy(new ProviderAllowList($secondaryId)));

$isRefilled = (
       ($values['restrict_providers'] ?? '') === '1'
    && ($values["allowed_provider_{$secondaryId}"] ?? '') === '1'
);

$suite->check('and the form is filled back in with them', $isRefilled, json_encode($values));

$readNoneTicked = function () use ($readForm, $form) : void {
    $readForm($form + ['restrict_providers' => '1']);
};

$suite->checkThrows('the toggle with nothing ticked is refused', InvalidInputException::class, $readNoneTicked);

$readMadeUp = function () use ($readForm, $form) : void {
    $readForm($form + ['restrict_providers' => '1', 'allowed_provider_' . ProviderId::createNew() => '1']);
};

$suite->checkThrows('as is ticking a provider that doesn\'t exist', InvalidInputException::class, $readMadeUp);

$suite->finish();
