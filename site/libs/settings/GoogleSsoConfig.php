<?php

/*
 * The google_sso section of settings.yaml: whether accounts can log in with Google, and the OAuth client to do it
 * with. The client's details are kept while it's switched off, so it can be switched back on without entering them
 * again. GoogleSsoSettings is what a login uses, which createSettings() only gives out while it's switched on.
 */

declare(strict_types = 1);


readonly class GoogleSsoConfig
{
    private bool $m_isEnabled;
    private string $m_clientId;
    private ConfigValue $m_clientSecret;
    private SsoMfaBypass $m_mfaBypass;


    /**
     * @param bool $isEnabled - whether accounts can log in with Google.
     *
     * @param string $clientId - the OAuth client's ID, from the Google Cloud console.
     *
     * @param ConfigValue $clientSecret - its secret, written out, or as a "${NAME}" reference.
     *
     * @param SsoMfaBypass $mfaBypass - whether logging in with Google skips this service's own second factor.
     *
     * @throws InvalidInputException - if it's switched on without a client ID and secret.
     */
    public function __construct(bool $isEnabled, string $clientId, ConfigValue $clientSecret, SsoMfaBypass $mfaBypass)
    {
        if ($isEnabled && (trim($clientId) === '' || trim($clientSecret->toConfigString()) === ''))
        {
            throw new InvalidInputException('Logging in with Google needs both the client ID and the client secret.');
        }

        $this->m_isEnabled = $isEnabled;
        $this->m_clientId = trim($clientId);
        $this->m_clientSecret = $clientSecret;
        $this->m_mfaBypass = $mfaBypass;
    }


    /**
     * Create the settings for when there's no google_sso section: switched off, with no client.
     * @return GoogleSsoConfig
     */
    public static function createDisabled() : GoogleSsoConfig
    {
        return new GoogleSsoConfig(false, '', ConfigValue::createFromString(''), SsoMfaBypass::NEVER);
    }


    /**
     * Get the settings a login uses, with the secret looked up (if it's a reference), and the URL Google sends people
     * back to.
     *
     * @param string $publicUrl - PUBLIC_URL, which the callback URL is made from.
     *
     * @return GoogleSsoSettings
     *
     * @throws ConfigurationException - if it's switched off, PUBLIC_URL is empty, or the secret refers to an
     * environment variable that isn't set.
     */
    public function createSettings(string $publicUrl) : GoogleSsoSettings
    {
        if (!$this->m_isEnabled)
        {
            throw new ConfigurationException('Logging in with Google isn\'t switched on.');
        }

        if ($publicUrl === '')
        {
            $msg = 'PUBLIC_URL must be set to log in with Google, for Google to send people back to.';
            throw new ConfigurationException($msg);
        }

        try
        {
            $settings = new GoogleSsoSettings(
                $this->m_clientId,
                $this->m_clientSecret->resolve(),
                $publicUrl . '/login/google/callback',
                $this->m_mfaBypass
            );
        }
        catch (InvalidInputException $e)
        {
            throw new ConfigurationException($e->getMessage());
        }

        return $settings;
    }


    # Accessors
    public function isEnabled() : bool { return $this->m_isEnabled; }
    public function getClientId() : string { return $this->m_clientId; }
    public function getClientSecret() : ConfigValue { return $this->m_clientSecret; }
    public function getMfaBypass() : SsoMfaBypass { return $this->m_mfaBypass; }
}
