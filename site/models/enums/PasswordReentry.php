<?php

/*
 * Whether a custom provider's saved password has to be entered again, when the server it's sent to changes (its host,
 * port, security or username), for ProviderFormInput. See SmtpSettings::findServerChanges().
 */

declare(strict_types = 1);


enum PasswordReentry
{
    // It has to be entered again, so it can't be moved to another server by someone who never knew it.
    case REQUIRED_FOR_NEW_SERVER;

    // It doesn't, as this web session entered it (see WebSession::hasEnteredProviderPassword()), e.g. while fixing a
    // typo in the host it was just saved with.
    case NOT_REQUIRED;
}
