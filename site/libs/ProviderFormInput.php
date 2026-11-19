<?php

/*
 * Reads the provider form into one provider's entry: the same data as an entry in settings.yaml's providers section
 * (and ProvidersConfigParser::toArray()), for ProvidersFormService to add or put in place of the one being edited.
 * Only the chosen type's fields are read, so another type's can't end up in it. ProvidersConfigParser checks it when
 * it's saved, so this only reads each field as its kind, and checks what it can say more clearly by the field's label.
 *
 * Each of a type's fields is named after the type and its key, e.g. "sendgrid_api_key", so the fields of the types
 * on the add form can't clash. A secret left blank when editing keeps the one it has.
 */

declare(strict_types = 1);


final class ProviderFormInput
{
    /**
     * Get the name of a field's input on the form.
     *
     * @param ProviderType $type - its type.
     *
     * @param ProviderField $field - the field.
     *
     * @return string - e.g. "sendgrid_api_key".
     */
    public static function getInputName(ProviderType $type, ProviderField $field) : string
    {
        return "{$type->value}_{$field->getKey()}";
    }


    /**
     * Read a provider's entry from the form.
     *
     * @param Closure(string): string $getField - gets a form field's value by name.
     *
     * @param ProviderType $type - the provider's type.
     *
     * @param ConfigSettingCollection $current - the settings of the provider being edited, so a secret left blank
     * keeps its value, or an empty collection when adding one.
     *
     * @param PasswordReentry $reentry - whether a custom provider's password, left blank to keep it, has to be entered
     * again if the server it's sent to changes. It does by default. Only pass NOT_REQUIRED for a session that entered
     * it (see WebSession::hasEnteredProviderPassword()).
     *
     * @return array<string, mixed> - the entry.
     *
     * @throws InvalidInputException - if a field can't be read, saying which, by its label, or a custom provider's
     * password has to be entered again.
     */
    public static function readEntry(
        Closure $getField,
        ProviderType $type,
        ConfigSettingCollection $current,
        PasswordReentry $reentry = PasswordReentry::REQUIRED_FOR_NEW_SERVER
    ) : array
    {
        $entry = [
            'name' => trim($getField('name')),
            'type' => $type->value,
            'enabled' => ($getField('enabled') === '1'),
        ];

        foreach (ProviderFormFields::getForType($type) as $field)
        {
            $entry[$field->getKey()] = self::readField($getField, $type, $field, $current);
        }

        if ($type === ProviderType::CUSTOM && $reentry === PasswordReentry::REQUIRED_FOR_NEW_SERVER)
        {
            self::assertPasswordGivenForNewServer($getField, $entry, $current);
        }

        $entry['rate_limits'] = [];

        foreach (PolicyInput::readRateLimitsFromForm($getField)->getLimits() as $limit)
        {
            $entry['rate_limits'][] = [
                'unit' => $limit->getUnit()->value,
                'max' => $limit->getMax(),
                'period_seconds' => $limit->getPeriodSeconds(),
            ];
        }

        return $entry;
    }


    /**
     * Check whether a custom provider's password was typed in on the form (rather than left blank, to keep the saved
     * one), so the session that typed it can be remembered as knowing it.
     *
     * @param Closure(string): string $getField - gets a form field's value by name.
     *
     * @return bool
     */
    public static function isCustomPasswordEntered(Closure $getField) : bool
    {
        return ($getField(ProviderType::CUSTOM->value . '_password') !== '');
    }


    /**
     * Read one of the type's fields, as its kind.
     *
     * @throws InvalidInputException
     */
    private static function readField(
        Closure $getField,
        ProviderType $type,
        ProviderField $field,
        ConfigSettingCollection $current
    ) : string|int
    {
        $name = self::getInputName($type, $field);
        $value = trim($getField($name));
        $label = $field->getLabel();

        $result = match ($field->getKind()) {
            ProviderFieldKind::SECRET => self::readSecret($getField, $name, $field, $current),
            ProviderFieldKind::NUMBER => self::readNumber($value, $label),
            ProviderFieldKind::CHOICE => self::readChoice($value, $field),
            ProviderFieldKind::TEXT, ProviderFieldKind::TEXTAREA => $value,
        };

        if ($field->isRequired() && $result === '')
        {
            throw new InvalidInputException("Fill in the {$label}.");
        }

        return $result;
    }


    /**
     * Check a custom provider's saved password isn't being kept (by leaving it blank) while the server it's sent to
     * changes (see SmtpSettings::findServerChanges()), so it can't be sent somewhere else without being entered again.
     *
     * @param Closure(string): string $getField - gets a form field's value by name.
     *
     * @param array<string, mixed> $entry - the provider's new settings, as read from the form.
     *
     * @param ConfigSettingCollection $current - its saved settings, or none when it's being added.
     *
     * @return void
     *
     * @throws InvalidInputException - if the password was left blank, to keep it, but the server changed.
     */
    private static function assertPasswordGivenForNewServer(
        Closure $getField,
        array $entry,
        ConfigSettingCollection $current
    ) : void
    {
        $hasSaved = (self::getCurrentValue($current, 'password') !== '');
        $isKept = (self::isCustomPasswordEntered($getField) === false && $hasSaved);
        $changed = $isKept ? SmtpSettings::findServerChanges($current, $entry) : [];

        if (count($changed) > 0)
        {
            throw new InvalidInputException(SmtpSettings::describePasswordNeeded(...$changed));
        }
    }


    /**
     * Read a secret: as it's entered, or the one it has, if it's left blank (when editing).
     *
     * @param Closure(string): string $getField - gets a form field's value by name.
     *
     * @param string $name - its input's name.
     *
     * @param ProviderField $field - the field.
     *
     * @param ConfigSettingCollection $current - the provider's saved settings, or none when it's being added.
     *
     * @return string
     */
    private static function readSecret(
        Closure $getField,
        string $name,
        ProviderField $field,
        ConfigSettingCollection $current
    ) : string
    {
        $entered = $getField($name);
        return ($entered === '') ? self::getCurrentValue($current, $field->getKey()) : $entered;
    }


    /**
     * @throws InvalidInputException - if it isn't a whole number.
     */
    private static function readNumber(string $value, string $label) : int
    {
        if (ctype_digit($value) === false)
        {
            throw new InvalidInputException("The {$label} must be a whole number, not \"{$value}\".");
        }

        return (int)$value;
    }


    /**
     * @throws InvalidInputException - if it isn't one of the field's options.
     */
    private static function readChoice(string $value, ProviderField $field) : string
    {
        if (array_key_exists($value, $field->getOptions()) === false)
        {
            throw new InvalidInputException("Choose one of the {$field->getLabel()}'s options.");
        }

        return $value;
    }


    /**
     * Get the value a setting has now, as it's written in settings.yaml, so a secret left blank when editing keeps
     * it. It stays on the server.
     *
     * @return string - or an empty string if it isn't set, or there's no provider being edited.
     */
    private static function getCurrentValue(ConfigSettingCollection $current, string $key) : string
    {
        $value = '';

        foreach ($current as $setting)
        {
            if ($setting->getName() === $key)
            {
                $settingValue = $setting->getValue();
                $isConfigValue = ($settingValue instanceof ConfigValue);
                $value = $isConfigValue ? $settingValue->toConfigString() : (string)$settingValue;
            }
        }

        return $value;
    }
}
