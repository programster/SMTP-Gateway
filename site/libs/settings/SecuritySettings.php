<?php

/*
 * The security section of settings.yaml: whether every account must use two-factor authentication, and what its
 * password must have (see PasswordPolicy). (What sits in front of the web interface is the proxies section: see
 * ProxySettings.)
 */

declare(strict_types = 1);


readonly class SecuritySettings
{
    private bool $m_isMfaRequired;
    private PasswordPolicy $m_passwordPolicy;


    /**
     * @param bool $isMfaRequired - whether every account must use two-factor authentication (an authenticator app, or
     * a security key). The API and SMTP server aren't affected, as they use tokens and keys.
     *
     * @param PasswordPolicy $passwordPolicy - what an account's password must have.
     */
    public function __construct(bool $isMfaRequired, PasswordPolicy $passwordPolicy)
    {
        $this->m_isMfaRequired = $isMfaRequired;
        $this->m_passwordPolicy = $passwordPolicy;
    }


    /**
     * Create the settings for when there's no security section, or it's invalid: a second factor required, the safe
     * choice, and the default password rules.
     *
     * @return SecuritySettings
     */
    public static function createDefault() : SecuritySettings
    {
        return new SecuritySettings(true, PasswordPolicy::createDefault());
    }


    # Accessors
    public function isMfaRequired() : bool { return $this->m_isMfaRequired; }
    public function getPasswordPolicy() : PasswordPolicy { return $this->m_passwordPolicy; }
}
