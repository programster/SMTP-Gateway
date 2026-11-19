<?php

/*
 * The settings for one type of provider, as written in its entry in settings.yaml (alongside the name, type,
 * enabled and rate_limits that every provider has). To add a provider type, implement this, and add it to
 * ProviderType and ProvidersConfigParser.
 */

declare(strict_types = 1);


interface ProviderSettingsInterface
{
    public function getType() : ProviderType;


    /**
     * Create the provider these settings describe.
     *
     * @param string $name - the provider's name in settings.yaml.
     *
     * @return SmtpProviderInterface
     *
     * @throws ConfigurationException - if a setting refers to an environment variable that isn't set.
     */
    public function createProvider(string $name) : SmtpProviderInterface;


    /**
     * Ask the provider how it's set up, for checking it can send as a key's senders (see SenderChecks). Unlike
     * createProvider(), this may make requests to the provider's API.
     *
     * @param string $name - the provider's name in settings.yaml.
     *
     * @return SenderCheckerInterface
     *
     * @throws ConfigurationException - if a setting refers to an environment variable that isn't set.
     *
     * @throws ProviderLookupException - if the provider's API couldn't be asked.
     */
    public function fetchSenderChecker(string $name) : SenderCheckerInterface;


    /**
     * Get the settings in the form they're written in settings.yaml, as setting name to value.
     *
     * @return ConfigSettingCollection - every setting, with whether each is a secret.
     */
    public function getSettings() : ConfigSettingCollection;
}
