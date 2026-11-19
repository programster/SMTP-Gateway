<?php

/*
 * Reads the provider form into one provider's entry: the same data as an entry in settings.yaml's providers section
 * (and ProvidersConfigParser::toArray()), for ProvidersFormService to add or put in place of the one being edited.
 * Only the chosen type's fields are read, so another type's can't end up in it. ProvidersConfigParser checks it when
 * it's saved, so this only reads each field as its kind, and checks what it can say more clearly by the field's label.
 *
 * Each of a type's fields is named after the type and its key, e.g. "sendgrid_api_key", so the fields of the types
 * on the add form can't clash. A secret also has "<name>_source" (a SecretSource) and "<name>_env" (a variable name).
 */

declare(strict_types = 1);


final class ProviderFormInput
{
    private const string ENV_VAR_PATTERN = '/^[A-Z_][A-Z0-9_]*$/';


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
     * @return array<string, mixed> - the entry.
     *
     * @throws InvalidInputException - if a field can't be read, saying which, by its label.
     */
    public static function readEntry(Closure $getField, ProviderType $type, ConfigSettingCollection $current) : array
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
     * Read a secret: entered (or left blank, to keep the one it has), or the name of the environment variable to
     * read it from.
     *
     * @throws InvalidInputException - if its source isn't one of SecretSource's, or the variable's name is invalid.
     */
    private static function readSecret(
        Closure $getField,
        string $name,
        ProviderField $field,
        ConfigSettingCollection $current
    ) : string
    {
        $source = SecretSource::tryFrom($getField("{$name}_source"));
        $envVar = trim($getField("{$name}_env"));
        $entered = $getField($name);

        if ($source === null)
        {
            throw new InvalidInputException("Choose where the {$field->getLabel()} comes from.");
        }

        $isValidEnvVar = (preg_match(self::ENV_VAR_PATTERN, $envVar) === 1);

        if ($source === SecretSource::ENVIRONMENT && $isValidEnvVar === false)
        {
            $msg =
                "The {$field->getLabel()}'s environment variable must be a name of capital letters, digits and"
                . " underscores, e.g. SENDGRID_API_KEY, not \"{$envVar}\".";

            throw new InvalidInputException($msg);
        }

        return match ($source) {
            SecretSource::ENVIRONMENT => '${' . $envVar . '}',
            SecretSource::ENTERED => ($entered === '') ? self::getCurrentValue($current, $field->getKey()) : $entered,
        };
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
     * Get the value a setting has now, as it's written in settings.yaml (a secret written out in full, or a
     * "${NAME}" reference), so a secret left blank when editing keeps it. It stays on the server.
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
