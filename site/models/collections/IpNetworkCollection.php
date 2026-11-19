<?php

declare(strict_types = 1);

use Programster\Collections\AbstractReadonlyCollection;


/**
 * @extends AbstractReadonlyCollection<IpNetwork>
 */
final readonly class IpNetworkCollection extends AbstractReadonlyCollection
{
    public function __construct(IpNetwork ...$ipNetworks)
    {
        parent::__construct(...$ipNetworks);
    }


    /**
     * Check whether an address is in any of the networks.
     * @param IpAddress $address - the address.
     * @return bool
     */
    public function includesAddress(IpAddress $address) : bool
    {
        $isIncluded = false;

        foreach ($this as $network)
        {
            /* @var $network IpNetwork */
            $isIncluded = ($isIncluded || $network->contains($address));
        }

        return $isIncluded;
    }
}
