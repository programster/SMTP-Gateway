<?php

/*
 * The settings a login with Google uses, from the OAuth client set up in the Google Cloud console. They're only made
 * (by GoogleSsoConfig::createSettings()) while logging in with Google is switched on, on the Settings page.
 */

declare(strict_types = 1);


readonly class GoogleSsoSettings
{
    private string $m_clientId;
    private string $m_clientSecret;
    private string $m_callbackUrl;
    private SsoMfaBypass $m_mfaBypass;


    /**
     * @param string $clientId - the OAuth client's ID.
     *
     * @param string $clientSecret - its secret.
     *
     * @param string $callbackUrl - where Google sends people back to after they've logged in, which must be one of
     * the client's authorised redirect URIs.
     *
     * @param SsoMfaBypass $mfaBypass - whether logging in with Google skips this service's own second factor.
     *
     * @throws InvalidInputException - if the ID or secret is empty.
     */
    public function __construct(string $clientId, string $clientSecret, string $callbackUrl, SsoMfaBypass $mfaBypass)
    {
        if (trim($clientId) === '' || trim($clientSecret) === '')
        {
            throw new InvalidInputException('Logging in with Google needs both the client ID and the client secret.');
        }

        $this->m_clientId = trim($clientId);
        $this->m_clientSecret = trim($clientSecret);
        $this->m_callbackUrl = $callbackUrl;
        $this->m_mfaBypass = $mfaBypass;
    }


    # Accessors
    public function getClientId() : string { return $this->m_clientId; }
    public function getClientSecret() : string { return $this->m_clientSecret; }
    public function getCallbackUrl() : string { return $this->m_callbackUrl; }
    public function getMfaBypass() : SsoMfaBypass { return $this->m_mfaBypass; }
}
