<?php

/*
 * Where an email is in its journey through this service.
 */

declare(strict_types = 1);


enum MessageStatus : string
{
    // Waiting for a rate limit to allow it, or to be retried after the provider failed temporarily.
    case QUEUED = 'queued';

    // Being handed to the provider right now.
    case SENDING = 'sending';

    // Accepted by the provider. What happened after that comes from the provider's tracking events.
    case SENT = 'sent';

    // The provider refused it, or kept failing until we gave up.
    case FAILED = 'failed';

    // Its email token was revoked before it could be sent.
    case CANCELLED = 'cancelled';
}
