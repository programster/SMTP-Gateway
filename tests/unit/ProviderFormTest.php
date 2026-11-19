<?php

/*
 * The provider form: that each type's fields (ProviderFormFields) are exactly the settings ProvidersConfigParser reads,
 * so neither can change without the other, and how ProviderFormInput reads each kind of field into a provider's entry.
 */

declare(strict_types = 1);

require_once __DIR__ . '/TestSuite.php';

$suite = new TestSuite('Provider form');

// A form with only each required field filled in, as a browser would send it.
$createRequiredForm = function (ProviderType $type) : array {
    $form = ['name' => "test-{$type->value}", 'enabled' => '1', 'type' => $type->value];

    foreach (ProviderFormFields::getForType($type) as $field)
    {
        $name = ProviderFormInput::getInputName($type, $field);

        $form[$name] = match ($field->getKind()) {
            ProviderFieldKind::NUMBER, ProviderFieldKind::CHOICE => $field->getDefault(),
            ProviderFieldKind::TEXT => $field->isRequired() ? ($field->getDefault() ?: 'login@mg.example.org') : '',
            ProviderFieldKind::TEXTAREA => '',
            ProviderFieldKind::SECRET => $field->isRequired() ? 'secret' : '',
        };
    }

    return $form;
};

$createReader = function (array $form) : Closure {
    $getField = function (string $name) use ($form) : string {
        return (string)($form[$name] ?? '');
    };

    return $getField;
};

$parse = function (array $entry) : ProvidersConfig {
    $data = ['mode' => ProviderMode::ROUND_ROBIN->value, 'providers' => [$entry]];
    return ProvidersConfigParser::parse($data, new ProviderDefinitionCollection());
};

$none = new ConfigSettingCollection();

foreach (ProviderType::cases() as $type)
{
    $form = $createRequiredForm($type);
    $entry = ProviderFormInput::readEntry($createReader($form), $type, $none);
    $provider = $parse($entry)->getProviders()[0];
    $settingKeys = [];

    foreach ($provider->getSettings()->getSettings() as $setting)
    {
        $settingKeys[] = $setting->getName();
    }

    $fieldKeys = [];

    foreach (ProviderFormFields::getForType($type) as $field)
    {
        $fieldKeys[] = $field->getKey();
    }

    $suite->check("{$type->value}: its required fields alone are a valid provider", $provider->getType() === $type);
    $isMatched = ($fieldKeys === $settingKeys);
    $suite->check("{$type->value}: its fields are exactly its settings", $isMatched, implode(', ', $settingKeys));

    foreach (ProviderFormFields::getForType($type) as $field)
    {
        if ($field->isRequired())
        {
            $blank = $form;
            $blank[ProviderFormInput::getInputName($type, $field)] = '';

            $readBlank = function () use ($createReader, $blank, $type, $none, $parse) : void {
                $parse(ProviderFormInput::readEntry($createReader($blank), $type, $none));
            };

            $name = "{$type->value}: {$field->getKey()} is required";
            $suite->checkThrows($name, InvalidInputException::class, $readBlank);
        }
    }
}

// Reading the kinds of field.
$type = ProviderType::CUSTOM;
$form = $createRequiredForm($type);
$form['custom_username'] = 'login';
$form['custom_password'] = 'mail-password';
$form['sendgrid_api_key'] = 'should-not-appear';
$entry = ProviderFormInput::readEntry($createReader($form), $type, $none);
$suite->check('a number is read as one', $entry['port'] === 587);
$suite->check('a secret is read as it\'s entered', $entry['password'] === 'mail-password', $entry['password']);
$isIgnored = (!in_array('should-not-appear', $entry, true) && !isset($entry['api_key']));
$suite->check('another type\'s fields are ignored', $isIgnored);

$badValues = [
    'a port that isn\'t a number' => ['custom_port' => 'smtp'],
    'a security that isn\'t one of its options' => ['custom_security' => 'starttls'],
];

foreach ($badValues as $description => $change)
{
    $read = function () use ($createReader, $form, $change, $type, $none) : void {
        ProviderFormInput::readEntry($createReader($change + $form), $type, $none);
    };

    $suite->checkThrows("{$description} is refused", InvalidInputException::class, $read);
}

// A secret left blank when editing keeps its value.
$current = new ConfigSettingCollection(
    new ConfigSetting('api_key', ConfigValue::createFromString('SG.written-out'), true),
    new ConfigSetting('webhook_public_key', ConfigValue::createFromString(''), false)
);

$form = $createRequiredForm(ProviderType::SENDGRID);
$form['sendgrid_api_key'] = '';
$entry = ProviderFormInput::readEntry($createReader($form), ProviderType::SENDGRID, $current);
$isKept = ($entry['api_key'] === 'SG.written-out');
$suite->check('a secret left blank when editing keeps its value', $isKept, $entry['api_key']);
$form['sendgrid_api_key'] = 'SG.new';
$entry = ProviderFormInput::readEntry($createReader($form), ProviderType::SENDGRID, $current);
$suite->check('but one entered replaces it', $entry['api_key'] === 'SG.new');

$readBlankNew = function () use ($createReader, $form, $none) : void {
    $form['sendgrid_api_key'] = '';
    ProviderFormInput::readEntry($createReader($form), ProviderType::SENDGRID, $none);
};

$suite->checkThrows('a required secret left blank when adding is refused', InvalidInputException::class, $readBlankNew);

// The form's values never include a secret.
$definition = $parse(['name' => 'shown', 'type' => 'sendgrid', 'api_key' => 'SG.never-shown'])->getProviders()[0];
$values = ProviderFormValues::createFromProvider($definition);
$suite->check('editing never fills a secret in', !in_array('SG.never-shown', $values, true));
$suite->check('but says it\'s set', ProviderFormValues::findSecretsSet($definition) === ['sendgrid_api_key' => true]);

// Choices can't be made without valid options.
$createBadChoice = function () : void {
    ProviderField::createChoice('region', 'Region', '', ['us' => 'US'], 'eu');
};

$suite->checkThrows('a choice whose default isn\'t an option is refused', LogicException::class, $createBadChoice);

$suite->finish();
