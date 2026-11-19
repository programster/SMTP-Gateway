<?php

/*
 * Whether logging in with Google lets an account skip this service's own second factor (google_sso.bypass_mfa in
 * settings.yaml, set on the Settings page).
 */

declare(strict_types = 1);


enum SsoMfaBypass : string
{
    // Logging in with Google only replaces the password: an account with a second factor still has to give it, and
    // requiring a second factor (security.require_mfa) still applies.
    case NEVER = 'never';

    // Logging in with Google is enough on its own, but only when Google confirms the person used two-step
    // verification to do it (an "amr" claim including "mfa", RFC 8176). Otherwise it's the same as NEVER. Google
    // doesn't send that claim at the moment, so for now this always acts as NEVER, which errs on the safe side.
    case AUTO = 'auto';

    // Logging in with Google is enough on its own: no second factor is asked for, and requiring one doesn't make the
    // session set one up. Logging in with the password still needs one.
    case ALWAYS = 'always';
}
