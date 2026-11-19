<?php

/*
 * What the provider form's inputs are filled in with, by input name (see ProviderFormInput::getInputName()): a new
 * provider's defaults, an existing provider's settings, or what was sent when saving failed. A secret's value is
 * never among them, so it can't end up in the page.
 */

declare(strict_types = 1);


final class ProviderFormValues
{
    /**
     * Get the values a new provider's form starts with: enabled, and each type's defaults, with each secret blank.
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
            }
        }

        return $values;
    }


    /**
     * Get the values a provider's form starts with when editing it: its settings, with each secret left blank (to
     * keep it).
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
            $text = ($value instanceof ConfigValue) ? $value->toConfigString() : (string)$value;
            $values[$name] = ($field->getKind() === ProviderFieldKind::SECRET) ? '' : $text;
        }

        return $values;
    }


    /**
     * Get what a form sent, to fill it in with again after it failed to save. Secrets' values are left out, so they
     * have to be entered again.
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
     * Get which of a provider's secrets are set now, by input name, so the form can say it will be kept if it's left
     * blank.
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
            $isWritten = ($value instanceof ConfigValue && $value->toConfigString() !== '');

            if ($setting->isSecret() && $isWritten)
            {
                $isSet["{$type->value}_{$setting->getName()}"] = true;
            }
        }

        return $isSet;
    }
}
