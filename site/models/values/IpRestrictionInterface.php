<?php

/*
 * Which IP addresses an email token may be used from: either a list of networks (IpAllowList), or no restriction at
 * all (AnyIp). "No restriction" is a type of its own, rather than an empty list, so an empty list can never be mistaken
 * for "nowhere", or the other way round.
 */

declare(strict_types = 1);


interface IpRestrictionInterface
{
    /**
     * Check whether an address is allowed.
     * @param IpAddress $address - the address.
     * @return bool
     */
    public function allows(IpAddress $address) : bool;


    /**
     * Describe what's allowed, for the web interface: the networks, one per line, or "Any IP address".
     * @return string
     */
    public function describe() : string;
}
