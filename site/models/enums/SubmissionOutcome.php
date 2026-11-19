<?php

/*
 * What happened to an email straight after it was accepted.
 */

declare(strict_types = 1);


enum SubmissionOutcome : string
{
    // Handed to the provider straight away.
    case SENT = 'sent';

    // Put in the queue, to be sent once the rate limits (or the provider) allow.
    case QUEUED = 'queued';
}
