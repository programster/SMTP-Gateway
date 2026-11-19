<?php

/*
 * The settings of the provider a new configuration replaces (the one with the same name and type), for the secrets
 * sent back as "<unchanged>", which keep the value they had. When there's no such provider, it's empty (none()), and
 * "<unchanged>" can't be used.
 */

declare(strict_types = 1);


readonly class PreviousSettings
{
    private bool $m_exists;
    private ConfigSettingCollection $m_settings;


    private function __construct(bool $exists, ConfigSettingCollection $settings)
    {
        $this->m_exists = $exists;
        $this->m_settings = clone $settings;
    }


    /**
     * There's no provider being replaced (it's a new one, or there's no valid configuration to replace).
     *
     * @return PreviousSettings
     */
    public static function none() : PreviousSettings
    {
        return new PreviousSettings(false, new ConfigSettingCollection());
    }


    public static function from(ProviderDefinition $provider) : PreviousSettings
    {
        return new PreviousSettings(true, $provider->getSettings()->getSettings());
    }


    /**
     * Get a setting's previous value.
     *
     * @param string $key - the setting, e.g. "api_key".
     *
     * @return ConfigValue - its value, or an empty one if the previous provider didn't have it set.
     *
     * @throws NotFoundException - if there's no previous provider.
     */
    public function getValue(string $key) : ConfigValue
    {
        if ($this->m_exists === false)
        {
            throw new NotFoundException('There\'s no previous provider to take the value from.');
        }

        $result = ConfigValue::createFromString('');

        foreach ($this->m_settings as $setting)
        {
            /* @var $setting ConfigSetting */
            if ($setting->getName() === $key && $setting->getValue() instanceof ConfigValue)
            {
                $result = $setting->getValue();
            }
        }

        return $result;
    }
}
