<?php

/*
 * Someone who has logged in with Google: their account here, and whether Google confirmed they used two-step
 * verification to do it (for the Settings page's "auto" choice of whether Google skips the second factor).
 */

declare(strict_types = 1);


readonly class GoogleLogin
{
    private AdminUser $m_user;
    private bool $m_isMfaConfirmed;


    /**
     * @param AdminUser $user - their account.
     *
     * @param bool $isMfaConfirmed - whether Google's ID token says they used more than one factor (an "amr" claim,
     * RFC 8176, including "mfa", "hwk" or "swk"), from programster/google-sso's hasMultiFactorAuthentication().
     */
    public function __construct(AdminUser $user, bool $isMfaConfirmed)
    {
        $this->m_user = $user;
        $this->m_isMfaConfirmed = $isMfaConfirmed;
    }


    # Accessors
    public function getUser() : AdminUser { return $this->m_user; }
    public function isMfaConfirmed() : bool { return $this->m_isMfaConfirmed; }
}
