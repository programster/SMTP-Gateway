<?php

/*
 * A setting in settings.yaml that can either be written out (e.g. "smtp.example.org"), or refer to an environment
 * variable, written as "${NAME}" (e.g. "${SENDGRID_API_KEY}"). References let the file be committed to version
 * control without the secrets in it. A reference is only looked up when the value is used, so a missing variable is
 * reported as a configuration error naming it.
 */

declare(strict_types = 1);


readonly class ConfigValue
{
    private const string REFERENCE_PATTERN = '/^\$\{([A-Z_][A-Z0-9_]*)\}$/';

    private string $m_envVarName;
    private string $m_literal;


    private function __construct(string $envVarName, string $literal)
    {
        $this->m_envVarName = $envVarName;
        $this->m_literal = $literal;
    }


    /**
     * Create the value from how it's written in settings.yaml.
     *
     * @param string $value - e.g. "smtp.example.org", or "${SMTP_PASSWORD}".
     *
     * @return ConfigValue
     *
     * @throws InvalidInputException - if it looks like a reference, but isn't a valid one (e.g. "${lower}").
     */
    public static function createFromString(string $value) : ConfigValue
    {
        if (preg_match(self::REFERENCE_PATTERN, $value, $matches) === 1)
        {
            $result = new ConfigValue($matches[1], '');
        }
        elseif (str_contains($value, '${'))
        {
            $msg =
                "\"{$value}\" looks like a reference to an environment variable, but isn't one. It must be the whole"
                . ' value, and the name must be in capitals, e.g. "${SMTP_PASSWORD}".';

            throw new InvalidInputException($msg);
        }
        else
        {
            $result = new ConfigValue('', $value);
        }

        return $result;
    }


    /**
     * Create a reference to an environment variable.
     *
     * @param string $envVarName - the variable's name, e.g. "SENDGRID_API_KEY".
     *
     * @return ConfigValue
     *
     * @throws InvalidInputException - if it isn't a valid name.
     */
    public static function createReference(string $envVarName) : ConfigValue
    {
        return self::createFromString('${' . $envVarName . '}');
    }


    public function isReference() : bool
    {
        return $this->m_envVarName !== '';
    }


    /**
     * Get the value to use: the environment variable's value for a reference, or the value as written.
     *
     * @return string
     *
     * @throws ConfigurationException - if it refers to an environment variable that isn't set.
     */
    public function resolve() : string
    {
        if ($this->isReference())
        {
            $value = Config::getEnv($this->m_envVarName);

            if ($value === '')
            {
                $msg =
                    "settings.yaml refers to \${{$this->m_envVarName}}, but the environment variable"
                    . " {$this->m_envVarName} isn't set. Add it to .env, and restart.";

                throw new ConfigurationException($msg);
            }
        }
        else
        {
            $value = $this->m_literal;
        }

        return $value;
    }


    /**
     * Get the value of an optional setting: as resolve() does, but an empty string, rather than an exception, if
     * it refers to an environment variable that isn't set, and trimmed.
     *
     * @return string
     */
    public function resolveIfAvailable() : string
    {
        return $this->isAvailable() ? trim($this->resolve()) : '';
    }


    /**
     * Whether the value can be used: written out and not empty, or a reference to a variable that's set.
     *
     * @return bool
     */
    public function isAvailable() : bool
    {
        return $this->isReference() ? Config::getEnv($this->m_envVarName) !== '' : $this->m_literal !== '';
    }


    /**
     * Get the value as it's written in settings.yaml.
     *
     * @return string
     */
    public function toConfigString() : string
    {
        return $this->isReference() ? '${' . $this->m_envVarName . '}' : $this->m_literal;
    }


    # Accessors
    public function getEnvVarName() : string { return $this->m_envVarName; }
}
