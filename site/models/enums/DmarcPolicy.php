<?php

/*
 * What a domain's DMARC record asks receivers to do with email that claims to be from it, but fails DMARC (it isn't
 * signed by, or sent from a server listed for, that domain).
 */

declare(strict_types = 1);


enum DmarcPolicy : string
{
    // Its DNS records couldn't be looked up.
    case UNKNOWN = 'unknown';

    // There's no DMARC record (or there's more than one, or it's invalid, which receivers treat the same way).
    case MISSING = 'missing';

    // Deliver it anyway, and only report it (p=none).
    case NONE = 'none';

    // Treat it as suspicious, e.g. put it in spam (p=quarantine).
    case QUARANTINE = 'quarantine';

    // Refuse it (p=reject).
    case REJECT = 'reject';


    /**
     * Whether email that fails DMARC is likely to be refused or put in spam.
     *
     * @return bool
     */
    public function isEnforced() : bool
    {
        return ($this === DmarcPolicy::QUARANTINE || $this === DmarcPolicy::REJECT);
    }
}
