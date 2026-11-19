<?php

/*
 * One setting of a provider in settings.yaml, e.g. its host, or its password.
 */

declare(strict_types = 1);


readonly class ConfigSetting
{
    private string $m_name;
    private string|int|ConfigValue $m_value;
    private bool $m_isSecret;


    /**
     * @param string $name - the setting's name in settings.yaml, e.g. "password".
     *
     * @param string|int|ConfigValue $value - its value. Settings that can refer to an environment variable are a
     * ConfigValue.
     *
     * @param bool $isSecret - whether it's a secret (a password or key), which is never shown, or exported, unless
     * it's a reference to an environment variable.
     */
    public function __construct(string $name, string|int|ConfigValue $value, bool $isSecret)
    {
        $this->m_name = $name;
        $this->m_value = $value;
        $this->m_isSecret = $isSecret;
    }


    # Accessors
    public function getName() : string { return $this->m_name; }
    public function getValue() : string|int|ConfigValue { return $this->m_value; }
    public function isSecret() : bool { return $this->m_isSecret; }
}
