<?php

/*
 * No restriction on one part of an email: every address is allowed. It's what an email token has for a part of the
 * email it was given no condition for.
 */

declare(strict_types = 1);


readonly class AnyAddress implements AddressRestrictionInterface
{
    public function allows(EmailAddress $address) : bool
    {
        return true;
    }


    public function allowsAll(EmailAddressCollection $addresses) : bool
    {
        return true;
    }


    public function allowsSomeOf(AddressRule $rule) : bool
    {
        return true;
    }


    public function describe() : string
    {
        return 'Anyone';
    }
}
