<?php

/*
 * What the provider form's inputs are filled in with, by input name (see ProviderFormInput::getInputName()): a new
 * provider's defaults, an existing provider's settings, or what was sent when saving failed. A secret's value is
 * never among them, so it can't end up in the page: only where it comes from (SecretSource), and a variable's name.
 */

declare(strict_types = 1);


final class ProviderFormValues
{
    /**
     * Get the values a new provider's form starts with: enabled, and each type's defaults, with each secret to be
     * entered, and its variable's name filled in with a suggestion, in case it's to come from the environment.
     *
     * @return array<string, string>
     */
    public static function createDefaults() : array
    {
        $values = ['type' => '', 'name' => '', 'enabled' => '1'];

        foreach (ProviderType::cases() as $type)
        {
            foreach (ProviderFormFields::getForType($type) as $field)
            {
                $name = ProviderFormInput::getInputName($type, $field);
                $values[$name] = $field->getDefault();

                if ($field->getKind() === ProviderFieldKind::SECRET)
                {
                    $values["{$name}_source"] = SecretSource::ENTERED->value;
                    $values["{$name}_env"] = strtoupper($name);
                }
            }
        }

        return $values;
    }


    /**
     * Get the values a provider's form starts with when editing it: its settings, with each secret that's a "${NAME}"
     * reference shown as coming from that variable, and each written out in full left blank (to keep it).
     *
     * @param ProviderDefinition $provider - the provider.
     *
     * @return array<string, string>
     */
    public static function createFromProvider(ProviderDefinition $provider) : array
    {
        $type = $provider->getType();
        $values = self::createDefaults();
        $values['type'] = $type->value;
        $values['name'] = $provider->getName();
        $values['enabled'] = $provider->isEnabled() ? '1' : '';
        $settings = [];

        foreach ($provider->getSettings()->getSettings() as $setting)
        {
            $settings[$setting->getName()] = $setting->getValue();
        }

        foreach (ProviderFormFields::getForType($type) as $field)
        {
            $name = ProviderFormInput::getInputName($type, $field);
            $value = $settings[$field->getKey()] ?? '';
            $isReference = ($value instanceof ConfigValue && $value->isReference());
            $text = ($value instanceof ConfigValue) ? $value->toConfigString() : (string)$value;

            if ($field->getKind() === ProviderFieldKind::SECRET && $isReference)
            {
                $values["{$name}_source"] = SecretSource::ENVIRONMENT->value;
                $values["{$name}_env"] = $value->getEnvVarName();
                $values[$name] = '';
            }
            elseif ($field->getKind() === ProviderFieldKind::SECRET)
            {
                $values[$name] = '';
            }
            else
            {
                $values[$name] = $text;
            }
        }

        return $values;
    }


    /**
     * Get what a form sent, to fill it in with again after it failed to save. Secrets' values are left out, so they
     * have to be entered again: only where each comes from, and its variable's name, are kept.
     *
     * @param Closure(string): string $getField - gets a form field's value by name.
     *
     * @return array<string, string>
     */
    public static function createFromForm(Closure $getField) : array
    {
        $values = self::createDefaults();

        foreach (array_keys($values) as $name)
        {
            $values[$name] = $getField($name);
        }

        foreach (ProviderType::cases() as $type)
        {
            foreach (ProviderFormFields::getForType($type) as $field)
            {
                if ($field->getKind() === ProviderFieldKind::SECRET)
                {
                    $values[ProviderFormInput::getInputName($type, $field)] = '';
                }
            }
        }

        return $values;
    }


    /**
     * Get which of a provider's secrets have a value written out in full now, by input name, so the form can say it
     * will be kept if it's left blank.
     *
     * @param ProviderDefinition $provider - the provider.
     *
     * @return array<string, bool>
     */
    public static function findSecretsSet(ProviderDefinition $provider) : array
    {
        $type = $provider->getType();
        $isSet = [];

        foreach ($provider->getSettings()->getSettings() as $setting)
        {
            $value = $setting->getValue();
            $isWritten = ($value instanceof ConfigValue && !$value->isReference() && $value->toConfigString() !== '');

            if ($setting->isSecret() && $isWritten)
            {
                $isSet["{$type->value}_{$setting->getName()}"] = true;
            }
        }

        return $isSet;
    }
}
