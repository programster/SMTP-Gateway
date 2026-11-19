<?php

/*
 * Gives out the providers configured in settings.yaml. Each is created the first time it's asked for, and the same
 * instance is given out after that, so a queue worker reuses its open connection to the provider. When
 * settings.yaml changes, every provider is disconnected and created afresh from the new configuration, so changes
 * take effect without a restart.
 */

declare(strict_types = 1);


final class ProviderRegistry
{
    private static ?ProviderInstanceMap $s_instances = null;
    private static ?ProvidersConfig $s_instancesConfig = null;


    /**
     * Get the providers in settings.yaml (reloaded whenever the file changes).
     *
     * @return ProvidersConfig
     *
     * @throws ProvidersNotConfiguredException - if none have been set up yet.
     * @throws ConfigurationException - if they aren't valid.
     */
    public static function getConfig() : ProvidersConfig
    {
        return SettingsFile::loadProviders();
    }


    /**
     * Get a provider.
     *
     * @param string $name - its name in settings.yaml.
     *
     * @return SmtpProviderInterface
     *
     * @throws NotFoundException - if there's no provider with that name.
     *
     * @throws ConfigurationException - if its settings refer to an environment variable that isn't set.
     */
    public static function getProvider(string $name) : SmtpProviderInterface
    {
        $config = self::getConfig();

        if (self::$s_instances === null || self::$s_instancesConfig !== $config)
        {
            self::disconnectAll();
            self::$s_instances = new ProviderInstanceMap();
            self::$s_instancesConfig = $config;
        }

        if (self::$s_instances->containsKey($name) === false)
        {
            $provider = $config->getProvider($name)->getSettings()->createProvider($name);
            self::$s_instances->put(new ProviderInstanceEntry($name, $provider));
        }

        return self::$s_instances->get($name);
    }


    /**
     * Close every provider's open connection, e.g. when a worker stops.
     *
     * @return void
     */
    public static function disconnectAll() : void
    {
        if (self::$s_instances !== null)
        {
            foreach (self::$s_instances as $provider)
            {
                /* @var $provider SmtpProviderInterface */
                $provider->disconnect();
            }
        }
    }
}
