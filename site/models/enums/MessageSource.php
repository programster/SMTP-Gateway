<?php

/*
 * How an email reached this service.
 */

declare(strict_types = 1);


enum MessageSource : string
{
    case SMTP = 'smtp';
    case API = 'api';

    // Sent by the service itself (e.g. a password reset link), not with an email token.
    case SYSTEM = 'system';
}
