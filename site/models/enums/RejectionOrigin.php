<?php

/*
 * Who turned a request to send an email away: this service itself, before anything was sent to a provider (wrong
 * credentials, an address the token isn't allowed, and so on), or a provider, which refused the email outright while
 * the client waited to be told what happened. For filtering the Rejected requests page.
 */

declare(strict_types = 1);


enum RejectionOrigin : string
{
    case ANY = 'any';
    case THIS_SERVICE = 'service';
    case PROVIDER = 'provider';


    public function getLabel() : string
    {
        return match ($this) {
            RejectionOrigin::ANY => 'Anyone',
            RejectionOrigin::THIS_SERVICE => 'This service',
            RejectionOrigin::PROVIDER => 'A provider, while the client waited',
        };
    }


    /**
     * Get the SQL condition for it, on the rejected_request table.
     * @return string
     */
    public function getSqlCondition() : string
    {
        $providerRejected = ServiceFactory::getDb()->escapeValue(RejectionReason::PROVIDER_REJECTED->value);

        return match ($this) {
            RejectionOrigin::ANY => 'TRUE',
            RejectionOrigin::THIS_SERVICE => "\"reason\" <> {$providerRejected}",
            RejectionOrigin::PROVIDER => "\"reason\" = {$providerRejected}",
        };
    }
}
