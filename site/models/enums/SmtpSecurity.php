<?php

/*
 * How to secure the connection to an upstream SMTP server.
 */

declare(strict_types = 1);


enum SmtpSecurity : string
{
    // Connect in plaintext, then upgrade with STARTTLS (usually port 587). The upgrade is required.
    case TLS = 'tls';

    // Implicit TLS from the start (usually port 465).
    case SSL = 'ssl';

    // Plaintext only. Only for local testing tools such as Mailpit.
    case NONE = 'none';
}
