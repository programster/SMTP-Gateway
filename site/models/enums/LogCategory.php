<?php

/*
 * What a log entry is about, kept in its context as "category", so the logs can be filtered to, say, only the changes
 * made to the service, without the entries about every email sent. Each place that logs has to say which it is (see
 * ServiceFactory::getLogger()).
 */

declare(strict_types = 1);


enum LogCategory : string
{
    // Changes made to the service: settings, accounts, API keys, email tokens, providers and rate limits.
    case ADMIN = 'admin';

    // Logging in, and accounts' security: failed logins, second factors, passwords and password resets.
    case ACCESS = 'access';

    // Email: sending, the queue, the SMTP server, providers' webhooks and status, and rejected requests.
    case EMAIL = 'email';

    // The service itself: unexpected errors, and the jobs it runs in the background.
    case SYSTEM = 'system';


    public function getLabel() : string
    {
        return match ($this) {
            LogCategory::ADMIN => 'Changes to the service',
            LogCategory::ACCESS => 'Logins and account security',
            LogCategory::EMAIL => 'Email',
            LogCategory::SYSTEM => 'System',
        };
    }
}
