<?php

/*
 * How someone logged in to the web interface: what they gave last, which is logged.
 */

declare(strict_types = 1);


enum LoginMethod : string
{
    // Their password, on an account with no second factor.
    case PASSWORD = 'password';

    // Their Google account, then the account's second factor, if it has one (unless Google skips it).
    case GOOGLE = 'Google';

    // Their Google account, without the account's second factor, as the Settings page says Google "always" skips it.
    case GOOGLE_BYPASSING_SECOND_FACTOR = 'Google, bypassing the second factor';

    // A code from their authenticator app, after their password or Google account.
    case AUTHENTICATOR_APP = 'authenticator app';

    // Their security key, after their password or Google account.
    case SECURITY_KEY = 'security key';

    // One of their backup codes, instead of their second factor, after their password or Google account.
    case BACKUP_CODE = 'backup code';
}
