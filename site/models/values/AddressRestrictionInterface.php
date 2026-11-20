<?php

/*
 * Which addresses an email token may use in one part of an email (its sender, reply-to or recipients): either a set of
 * rules (AddressRuleSet), or no restriction at all (AnyAddress). "No restriction" is a type of its own, rather than an
 * empty set of rules, so an empty list can never be mistaken for "nobody", or the other way round.
 */

declare(strict_types = 1);


interface AddressRestrictionInterface
{
    /**
     * Check whether an address is allowed.
     * @param EmailAddress $address - the address.
     * @return bool
     */
    public function allows(EmailAddress $address) : bool;


    /**
     * Check whether every address is allowed, e.g. an email's envelope sender and its From and Sender addresses.
     * @param EmailAddressCollection $addresses - the addresses.
     * @return bool - true if they all are (or there are none).
     */
    public function allowsAll(EmailAddressCollection $addresses) : bool;


    /**
     * Check whether some address a rule allows would be allowed too, e.g. whether a provider could take any of the
     * senders an email token's rule allows.
     *
     * @param AddressRule $rule - the rule.
     *
     * @return bool
     */
    public function allowsSomeOf(AddressRule $rule) : bool;


    /**
     * Describe what's allowed, for the web interface: the rules in their written form, one per line, or "Anyone".
     * @return string
     */
    public function describe() : string;
}
