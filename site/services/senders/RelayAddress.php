<?php

/*
 * One of the addresses a "custom" provider's SMTP server resolves to, with its reverse DNS, for the checks that
 * receivers make on the server that sends them email (see CustomSenderChecker).
 */

declare(strict_types = 1);


final readonly class RelayAddress
{
    private string $m_ip;
    private array $m_ptrNames;
    private string $m_confirmedName;


    /**
     * @param string $ip - the address.
     *
     * @param list<string> $ptrNames - its reverse DNS (PTR) names, or an empty list if it has none.
     *
     * @param string $confirmedName - the PTR name that resolves back to the address ("forward-confirmed"), or an
     * empty string if none does.
     *
     * @throws InvalidInputException - if $ip isn't an IP address, or $confirmedName isn't one of $ptrNames.
     */
    public function __construct(string $ip, array $ptrNames, string $confirmedName)
    {
        if (inet_pton($ip) === false)
        {
            throw new InvalidInputException("{$ip} isn't an IP address.");
        }

        if ($confirmedName !== '' && !in_array($confirmedName, $ptrNames, true))
        {
            throw new InvalidInputException("{$confirmedName} isn't one of {$ip}'s PTR names.");
        }

        $this->m_ip = $ip;
        $this->m_ptrNames = $ptrNames;
        $this->m_confirmedName = $confirmedName;
    }


    /**
     * Look up an address's reverse DNS, and whether it's forward-confirmed.
     *
     * @param DnsResolverInterface $dns - what to look it up with.
     *
     * @param string $ip - the address.
     *
     * @return RelayAddress
     *
     * @throws DnsLookupException - if a lookup failed.
     */
    public static function fetch(DnsResolverInterface $dns, string $ip) : RelayAddress
    {
        $names = $dns->fetchPtrNames($ip);
        $confirmed = '';

        foreach ($names as $name)
        {
            if ($confirmed === '' && self::containsAddress($dns->fetchIpAddresses($name), $ip))
            {
                $confirmed = $name;
            }
        }

        return new RelayAddress($ip, $names, $confirmed);
    }


    /**
     * Whether it's a public address: not a private (e.g. 10.0.0.0/8), loopback or otherwise reserved one, which
     * receivers on the internet would never see email coming from.
     *
     * @return bool
     */
    public function isPublic() : bool
    {
        $flags = FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE;
        return (filter_var($this->m_ip, FILTER_VALIDATE_IP, $flags) !== false);
    }


    /**
     * Check whether a list of addresses holds an address, comparing them in binary (so "2001:db8::1" matches
     * "2001:0db8:0:0:0:0:0:1").
     */
    private static function containsAddress(array $addresses, string $ip) : bool
    {
        $isFound = false;

        foreach ($addresses as $address)
        {
            $isFound = $isFound || (inet_pton($address) === inet_pton($ip));
        }

        return $isFound;
    }


    # Accessors
    public function getIp() : string { return $this->m_ip; }
    public function getPtrNames() : array { return $this->m_ptrNames; }
    public function getConfirmedName() : string { return $this->m_confirmedName; }
}
