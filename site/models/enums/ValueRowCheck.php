<?php

/*
 * How each row of a list on a form (see ViewValueRows) is checked in the browser as it's typed, before it's sent. The
 * server checks every value again, whatever the browser did.
 */

declare(strict_types = 1);


enum ValueRowCheck : string
{
    // A single IPv4 or IPv6 address, or a CIDR range of them, e.g. "10.0.0.0/8".
    case IP_NETWORK = 'ip-network';

    // An address rule: "*@example.org" (anyone at a domain), or a single address. A "*" on its own isn't one, as
    // anyone is allowed by leaving the list empty.
    case ADDRESS_RULE = 'address-rule';

    // A domain, e.g. "example.org".
    case DOMAIN = 'domain';

    // A single email address, e.g. "someone@example.org".
    case EMAIL_ADDRESS = 'email-address';
}
