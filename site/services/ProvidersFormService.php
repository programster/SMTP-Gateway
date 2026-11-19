<?php

/*
 * Makes the changes the provider forms on the Providers page ask for: adding, editing and removing a provider,
 * putting the enabled providers in order, and setting the mode. Each change is made to the providers' data (as
 * ProvidersConfigParser::toArray() gives it, with the secrets written out in full as "<unchanged>", so they're kept
 * without leaving the server), which is then parsed and saved exactly as the YAML editor's and the API's changes are. So every check they have (valid,
 * unique names, at least one enabled provider, each type's settings, and the version the change was based on) applies
 * to the forms too.
 */

declare(strict_types = 1);


final class ProvidersFormService
{
    /**
     * Get a provider, to edit.
     *
     * @param string $name - its name.
     *
     * @return ProviderDefinition
     *
     * @throws NotFoundException - if there's no such provider.
     * @throws ConfigurationException - if the providers can't be read.
     */
    public static function loadProvider(string $name) : ProviderDefinition
    {
        $found = null;
        $providers = ProviderRegistry::getConfig()->getProviders();
        $index = 0;

        while ($found === null && $index < count($providers))
        {
            $found = ($providers[$index]->getName() === $name) ? $providers[$index] : null;
            $index++;
        }

        if ($found === null)
        {
            throw new NotFoundException("There's no provider called \"{$name}\".");
        }

        return $found;
    }


    /**
     * Add a provider, after the others.
     *
     * @param array<string, mixed> $entry - its entry (see ProviderFormInput).
     *
     * @param string $version - the version of the providers the change is based on.
     *
     * @return void
     *
     * @throws InvalidInputException - if it isn't valid (e.g. its name is taken).
     * @throws ConflictException - if the providers have changed since the version given.
     * @throws ConfigurationException - if the providers can't be read or saved.
     */
    public static function add(array $entry, string $version) : void
    {
        $data = self::loadData();
        $data['providers'][] = $entry;
        self::save($data, $version);
    }


    /**
     * Put a provider's new entry in place of its old one, which may rename it.
     *
     * @param string $name - its name now.
     *
     * @param array<string, mixed> $entry - its new entry (see ProviderFormInput).
     *
     * @param string $version - the version of the providers the change is based on.
     *
     * @return void
     *
     * @throws NotFoundException - if there's no such provider.
     * @throws InvalidInputException - if it isn't valid.
     * @throws ConflictException - if the providers have changed since the version given.
     * @throws ConfigurationException - if the providers can't be read or saved.
     */
    public static function edit(string $name, array $entry, string $version) : void
    {
        $data = self::loadData();
        $data['providers'][self::findIndex($data, $name)] = $entry;
        self::save($data, $version);
    }


    /**
     * Remove a provider. The last one that's enabled can't be, as no email could be sent.
     *
     * @param string $name - its name.
     *
     * @param string $version - the version of the providers the change is based on.
     *
     * @return void
     *
     * @throws NotFoundException - if there's no such provider.
     * @throws InvalidInputException - if it's the last enabled one.
     * @throws ConflictException - if the providers have changed since the version given.
     * @throws ConfigurationException - if the providers can't be read or saved.
     */
    public static function remove(string $name, string $version) : void
    {
        $data = self::loadData();
        array_splice($data['providers'], self::findIndex($data, $name), 1);
        self::save($data, $version);
    }


    /**
     * Put the enabled providers in an order, which is the order they're tried in, for the overflow and fallback
     * modes. The disabled ones, which aren't tried, go after them, in the order they were in.
     *
     * @param string $version - the version of the providers the change is based on.
     *
     * @param string ...$enabledNames - the name of every enabled provider, each once, in their new order.
     *
     * @return void
     *
     * @throws InvalidInputException - if the names aren't exactly the enabled providers', e.g. one was added since.
     * @throws ConflictException - if the providers have changed since the version given.
     * @throws ConfigurationException - if the providers can't be read or saved.
     */
    public static function reorder(string $version, string ...$enabledNames) : void
    {
        $data = self::loadData();
        $enabled = [];
        $disabled = [];

        foreach ($data['providers'] as $entry)
        {
            if (($entry['enabled'] ?? true) === false)
            {
                $disabled[] = $entry;
            }
            else
            {
                $enabled[(string)$entry['name']] = $entry;
            }
        }

        $given = $enabledNames;
        $current = array_keys($enabled);
        sort($given);
        sort($current);

        if ($given !== $current)
        {
            $msg = 'The order must have every enabled provider in it, once. Reload the page, and try again.';
            throw new InvalidInputException($msg);
        }

        $ordered = [];

        foreach ($enabledNames as $name)
        {
            $ordered[] = $enabled[$name];
        }

        $data['providers'] = array_merge($ordered, $disabled);
        self::save($data, $version);
    }


    /**
     * Set how emails are shared between the providers.
     *
     * @param ProviderMode $mode - the mode.
     *
     * @param string $version - the version of the providers the change is based on.
     *
     * @return void
     *
     * @throws ConflictException - if the providers have changed since the version given.
     * @throws ConfigurationException - if the providers can't be read or saved.
     */
    public static function setMode(ProviderMode $mode, string $version) : void
    {
        $data = self::loadData();
        $data['mode'] = $mode->value;
        self::save($data, $version);
    }


    /**
     * Get the providers' data, with the secrets written out in full as "<unchanged>", or none, on a fresh install.
     *
     * @return array{mode: string, providers: list<array<string, mixed>>}
     *
     * @throws ConfigurationException - if the providers can't be read (e.g. settings.yaml was broken by hand).
     */
    private static function loadData() : array
    {
        try
        {
            $data = ProvidersConfigParser::toArray(ProviderRegistry::getConfig(), SecretOutput::MASKED);
        }
        catch (ProvidersNotConfiguredException)
        {
            $data = ['mode' => ProviderMode::ROUND_ROBIN->value, 'providers' => []];
        }

        return $data;
    }


    /**
     * @throws NotFoundException - if there's no provider of that name in the data.
     */
    private static function findIndex(array $data, string $name) : int
    {
        $names = array_column($data['providers'], 'name');
        $index = array_search($name, $names, true);

        if ($index === false)
        {
            throw new NotFoundException("There's no provider called \"{$name}\".");
        }

        return (int)$index;
    }


    /**
     * Parse and save the providers' data, as the YAML editor does.
     *
     * @throws InvalidInputException
     * @throws ConflictException
     * @throws ConfigurationException
     */
    private static function save(array $data, string $version) : void
    {
        SettingsFile::saveProviders(SettingsFile::parseProvidersData($data), $version);
    }
}
