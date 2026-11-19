<?php

/*
 * How one attempt at handing an email to a provider ended (see the email_attempt table).
 */

declare(strict_types = 1);


enum AttemptOutcome : string
{
    // It's being handed over now.
    case SENDING = 'sending';

    // The provider accepted it.
    case SENT = 'sent';

    // The provider failed in a way that may work later (a 4xx reply or a timeout), so another was tried.
    case TEMPORARY_FAILURE = 'temporary_failure';

    // The provider refused it outright (a 5xx reply).
    case REFUSED = 'refused';

    // The provider couldn't be connected to, or logged in to.
    case UNAVAILABLE = 'unavailable';

    // The provider refused it as over its own rate limit (or quota), so another was tried (see RateLimitReply).
    case RATE_LIMITED = 'rate_limited';

    // The process sending it died part way through, so whether the provider got it isn't known.
    case INTERRUPTED = 'interrupted';


    public function getLabel() : string
    {
        return match ($this) {
            AttemptOutcome::SENDING => 'sending',
            AttemptOutcome::SENT => 'accepted',
            AttemptOutcome::TEMPORARY_FAILURE => 'failed, temporarily',
            AttemptOutcome::REFUSED => 'refused',
            AttemptOutcome::UNAVAILABLE => 'unreachable',
            AttemptOutcome::RATE_LIMITED => 'over its rate limit',
            AttemptOutcome::INTERRUPTED => 'interrupted',
        };
    }
}
