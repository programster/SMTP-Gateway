<?php

/*
 * No restriction on where an email token may be used from: every IP address is allowed. It's what an email token has
 * when it was given no client IP condition.
 */

declare(strict_types = 1);


readonly class AnyIp implements IpRestrictionInterface
{
    public function allows(IpAddress $address) : bool
    {
        return true;
    }


    public function describe() : string
    {
        return 'Any IP address';
    }
}
