<?php

/*
 * Where an email that hasn't been sent (yet) has got to, for the dashboard's breakdown of what didn't go through, and
 * the Emails page's filter:
 *
 * - RETRYING: queued, after at least one provider failed to take it, waiting to be tried again.
 * - FAILED_AFTER_QUEUEING: accepted and queued, then failed with every provider it was tried with.
 * - REFUSED_WHILE_WAITING: refused outright by a provider while the client waited to be told what happened, so the
 *   client was told it was rejected (it's also a rejected request), and it was never queued.
 *
 * ANY is no filter.
 */

declare(strict_types = 1);


enum MessageProgress : string
{
    case ANY = 'any';
    case RETRYING = 'retrying';
    case FAILED_AFTER_QUEUEING = 'failed_queued';
    case REFUSED_WHILE_WAITING = 'refused_waiting';


    public function getLabel() : string
    {
        return match ($this) {
            MessageProgress::ANY => 'Anything',
            MessageProgress::RETRYING => 'Being retried',
            MessageProgress::FAILED_AFTER_QUEUEING => 'Failed, after being queued',
            MessageProgress::REFUSED_WHILE_WAITING => 'Refused while the client waited',
        };
    }


    /**
     * Get the SQL condition for it, on the email_message table, as "m". The service's own emails are never waited
     * for, so they're always "after being queued".
     *
     * @return string
     */
    public function getSqlCondition() : string
    {
        $retrying = '"m"."status" = \'queued\' AND jsonb_array_length("m"."tried_providers") > 0';
        $wasQueued = '("m"."was_queued" OR "m"."source" = \'system\')';

        return match ($this) {
            MessageProgress::ANY => 'TRUE',
            MessageProgress::RETRYING => $retrying,
            MessageProgress::FAILED_AFTER_QUEUEING => "\"m\".\"status\" = 'failed' AND {$wasQueued}",
            MessageProgress::REFUSED_WHILE_WAITING => "\"m\".\"status\" = 'failed' AND NOT {$wasQueued}",
        };
    }
}
