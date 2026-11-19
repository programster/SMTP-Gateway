<?php

/*
 * A single IPv4 or IPv6 address. IPv4 addresses written in their IPv6-mapped form (e.g. "::ffff:10.0.0.1") are
 * turned into plain IPv4 addresses, so they match IPv4 networks as expected.
 */

declare(strict_types = 1);


readonly class IpAddress
{
    private string $m_packed;


    /**
     * @param string $address - the address, e.g. "10.0.0.1" or "2001:db8::1".
     *
     * @throws InvalidInputException - if it isn't a valid IP address.
     */
    public function __construct(string $address)
    {
        $packed = @inet_pton(trim($address));

        if ($packed === false)
        {
            throw new InvalidInputException("\"{$address}\" isn't a valid IP address.");
        }

        $mappedPrefix = str_repeat("\0", 10) . "\xff\xff";

        if (strlen($packed) === 16 && str_starts_with($packed, $mappedPrefix))
        {
            $packed = substr($packed, 12);
        }

        $this->m_packed = $packed;
    }


    /**
     * Whether some text is an IP address, e.g. to check a header's value before believing it.
     * @param string $address - the text.
     * @return bool
     */
    public static function isValid(string $address) : bool
    {
        return @inet_pton(trim($address)) !== false;
    }


    /**
     * Check whether it's an address that's only used inside a network, never on the internet: a private address
     * (10.0.0.0/8, 172.16.0.0/12, 192.168.0.0/16, or IPv6's fc00::/7), the machine's own (loopback), a link-local
     * one, or a carrier-grade NAT one (100.64.0.0/10). A web request from one has usually come through a proxy on the
     * same network, unless the service is reached directly from inside a home or office network.
     *
     * @return bool
     */
    public function isPrivate() : bool
    {
        static $networks = null;

        if ($networks === null)
        {
            $ranges = [
                '10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16', '127.0.0.0/8', '169.254.0.0/16', '100.64.0.0/10',
                'fc00::/7', '::1/128', 'fe80::/10',
            ];

            $created = [];

            foreach ($ranges as $range)
            {
                $created[] = new IpNetwork($range);
            }

            $networks = new IpNetworkCollection(...$created);
        }

        return $networks->includesAddress($this);
    }


    public function isIpv4() : bool
    {
        return strlen($this->m_packed) === 4;
    }


    public function __toString() : string
    {
        return (string)inet_ntop($this->m_packed);
    }


    # Accessors
    public function getPacked() : string { return $this->m_packed; }
}
