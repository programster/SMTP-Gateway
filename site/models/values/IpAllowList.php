<?php

/*
 * The networks that an email token or API key may be used from. It can't be empty, as that would read as "nowhere".
 * An API key must always have one, so allowing any address there means explicitly allowing "0.0.0.0/0" and "::/0".
 * An email token can instead have no restriction at all (AnyIp).
 */

declare(strict_types = 1);


readonly class IpAllowList implements IpRestrictionInterface
{
    private IpNetworkCollection $m_networks;


    /**
     * @param IpNetwork ...$networks - the networks to allow. At least one is required. Duplicates are dropped.
     *
     * @throws InvalidInputException - if no networks were given.
     */
    public function __construct(IpNetwork ...$networks)
    {
        if (count($networks) === 0)
        {
            $msg = 'At least one allowed IP address or range is required. Use 0.0.0.0/0 and ::/0 to allow any.';
            throw new InvalidInputException($msg);
        }

        $unique = [];
        $seen = [];

        foreach ($networks as $network)
        {
            if (!isset($seen[(string)$network]))
            {
                $seen[(string)$network] = true;
                $unique[] = $network;
            }
        }

        $this->m_networks = new IpNetworkCollection(...$unique);
    }


    /**
     * Check whether an address is within any of the allowed networks.
     *
     * @param IpAddress $address - the address to check.
     *
     * @return bool
     */
    public function allows(IpAddress $address) : bool
    {
        $allowed = false;

        foreach ($this->m_networks as $network)
        {
            /* @var $network IpNetwork */
            if ($allowed === false && $network->contains($address))
            {
                $allowed = true;
            }
        }

        return $allowed;
    }


    public function describe() : string
    {
        return $this->toText();
    }


    /**
     * Get the networks in their written form, one per line.
     *
     * @return string
     */
    public function toText() : string
    {
        return implode("\n", $this->toStrings());
    }


    /**
     * Get each network in its written form, e.g. ["10.0.0.0/8", "192.168.1.20/32"].
     *
     * @return string[]
     */
    public function toStrings() : array
    {
        $strings = [];

        foreach ($this->m_networks as $network)
        {
            $strings[] = (string)$network;
        }

        return $strings;
    }


    # Accessors
    public function getNetworks() : IpNetworkCollection { return clone $this->m_networks; }
}
