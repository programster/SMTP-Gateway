<?php

/*
 * A setting's value in settings.yaml, as it's written there (e.g. "smtp.example.org", or a password). Settings aren't
 * read from environment variables: a value written as a reference to one (e.g. "${SENDGRID_API_KEY}"), which an
 * earlier version allowed, is refused, saying to put the value itself there instead. A value that only contains "${"
 * somewhere (e.g. a random password) is just a value.
 */

declare(strict_types = 1);


readonly class ConfigValue
{
    // What an earlier version took to be a reference to an environment variable.
    private const string REFERENCE_PATTERN = '/^\$\{([A-Z_][A-Z0-9_]*)\}$/';

    private string $m_value;


    private function __construct(string $value)
    {
        $this->m_value = $value;
    }


    /**
     * Create the value from how it's written in settings.yaml.
     *
     * @param string $value - e.g. "smtp.example.org".
     *
     * @return ConfigValue
     *
     * @throws InvalidInputException - if it's a reference to an environment variable (e.g. "${SMTP_PASSWORD}"), which
     * settings can't be read from.
     */
    public static function createFromString(string $value) : ConfigValue
    {
        if (preg_match(self::REFERENCE_PATTERN, $value, $matches) === 1)
        {
            $msg =
                "\"{$value}\" is a reference to the environment variable {$matches[1]}, but settings aren't read from"
                . ' environment variables. Put the value itself here instead, e.g. by entering it in the web'
                . ' interface.';

            throw new InvalidInputException($msg);
        }

        return new ConfigValue($value);
    }


    /**
     * Get the value to use.
     * @return string
     */
    public function resolve() : string
    {
        return $this->m_value;
    }


    /**
     * Get the value of an optional setting, trimmed: an empty string if it isn't set.
     * @return string
     */
    public function resolveIfAvailable() : string
    {
        return trim($this->m_value);
    }


    /**
     * Whether the value is set (isn't empty).
     * @return bool
     */
    public function isAvailable() : bool
    {
        return $this->m_value !== '';
    }


    /**
     * Get the value as it's written in settings.yaml.
     * @return string
     */
    public function toConfigString() : string
    {
        return $this->m_value;
    }
}
