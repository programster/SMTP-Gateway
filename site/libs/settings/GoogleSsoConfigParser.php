<?php

/*
 * Turns the google_sso section of settings.yaml into a GoogleSsoConfig, and back again. Any setting that's left out
 * has its default, so a section with nothing in it leaves logging in with Google switched off:
 *
 *     google_sso:
 *       enabled: true                       # false by default
 *       client_id: "123.apps.googleusercontent.com"
 *       client_secret: "${GOOGLE_SSO_CLIENT_SECRET}"   # in full, or a reference to an environment variable
 *       bypass_mfa: "never"                 # never (the default), auto or always
 */

declare(strict_types = 1);


final class GoogleSsoConfigParser
{


    /**
     * Read the section.
     *
     * @param mixed $data - the section, as decoded.
     *
     * @return GoogleSsoConfig
     *
     * @throws InvalidInputException - if a setting is unknown or invalid. The message says which.
     */
    public static function parse(mixed $data) : GoogleSsoConfig
    {
        $section = SettingsSection::GOOGLE_SSO;
        $settings = SettingsYaml::readMapping($data, $section, 'enabled', 'client_id', 'client_secret', 'bypass_mfa');
        $isEnabled = $settings['enabled'] ?? false;
        $clientId = $settings['client_id'] ?? '';
        $clientSecret = $settings['client_secret'] ?? '';
        $bypassName = $settings['bypass_mfa'] ?? SsoMfaBypass::NEVER->value;
        $bypass = is_string($bypassName) ? SsoMfaBypass::tryFrom($bypassName) : null;

        if (!is_bool($isEnabled))
        {
            throw new InvalidInputException('"google_sso.enabled" must be true or false.');
        }
        elseif (!is_string($clientId) || !is_string($clientSecret))
        {
            throw new InvalidInputException('"google_sso.client_id" and "google_sso.client_secret" must be text.');
        }
        elseif ($bypass === null)
        {
            $allowed = implode(', ', array_column(SsoMfaBypass::cases(), 'value'));
            throw new InvalidInputException("\"google_sso.bypass_mfa\" must be one of: {$allowed}.");
        }

        return new GoogleSsoConfig($isEnabled, $clientId, ConfigValue::createFromString($clientSecret), $bypass);
    }


    /**
     * Get the section as YAML, without its "google_sso:" key or indentation, with the secret as it is.
     * @param GoogleSsoConfig $config
     * @return string
     */
    public static function toYaml(GoogleSsoConfig $config) : string
    {
        $secretLine = 'client_secret: ' . SettingsYaml::quote($config->getClientSecret()->toConfigString());

        $lines = [
            '# Logging in to the web interface with Google, set on the Settings page.',
            'enabled: ' . SettingsYaml::quote($config->isEnabled()),
            'client_id: ' . SettingsYaml::quote($config->getClientId()),
            $secretLine,
            '# never, auto or always: whether logging in with Google skips this service\'s own second factor.',
            'bypass_mfa: ' . SettingsYaml::quote($config->getMfaBypass()->value),
        ];

        return implode("\n", $lines);
    }
}
