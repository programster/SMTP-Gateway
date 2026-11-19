<?php

/*
 * A range of IP addresses in CIDR notation, e.g. "10.0.0.0/8" or "2001:db8::/32". A single address can be given
 * without a prefix length, e.g. "10.0.0.5", which is the same as "10.0.0.5/32".
 */

declare(strict_types = 1);


readonly class IpNetwork
{
    private string $m_packedNetwork;
    private int $m_prefixLength;


    /**
     * @param string $cidr - the network, e.g. "192.168.1.0/24", or a single address such as "192.168.1.5".
     *
     * @throws InvalidInputException - if it isn't a valid network, or it has bits set after the prefix (e.g.
     * "192.168.1.5/24", which is ambiguous: did you mean "192.168.1.0/24" or "192.168.1.5/32"?).
     */
    public function __construct(string $cidr)
    {
        $cidr = trim($cidr);
        $parts = explode('/', $cidr);

        if (count($parts) > 2)
        {
            throw new InvalidInputException("\"{$cidr}\" isn't a valid IP address or CIDR range.");
        }

        $address = new IpAddress($parts[0]);
        $maxPrefixLength = strlen($address->getPacked()) * 8;

        if (count($parts) === 2)
        {
            if (preg_match('/^\d{1,3}$/', $parts[1]) !== 1 || (int)$parts[1] > $maxPrefixLength)
            {
                $msg = "\"{$cidr}\" has an invalid prefix length. It must be from 0 to {$maxPrefixLength}.";
                throw new InvalidInputException($msg);
            }

            $prefixLength = (int)$parts[1];
        }
        else
        {
            $prefixLength = $maxPrefixLength;
        }

        $network = self::applyMask($address->getPacked(), $prefixLength);

        if ($network !== $address->getPacked())
        {
            $networkString = inet_ntop($network);

            $msg =
                "\"{$cidr}\" has bits set after its prefix length. Did you mean \"{$networkString}/{$prefixLength}\""
                . " (the whole range), or \"{$address}/{$maxPrefixLength}\" (just that address)?";

            throw new InvalidInputException($msg);
        }

        $this->m_packedNetwork = $network;
        $this->m_prefixLength = $prefixLength;
    }


    /**
     * Check whether an address is within this network. An IPv4 address is never within an IPv6 network, or vice
     * versa.
     *
     * @param IpAddress $address - the address to check.
     *
     * @return bool - true if it's within the network.
     */
    public function contains(IpAddress $address) : bool
    {
        $result = false;
        $packed = $address->getPacked();

        if (strlen($packed) === strlen($this->m_packedNetwork))
        {
            $result = (self::applyMask($packed, $this->m_prefixLength) === $this->m_packedNetwork);
        }

        return $result;
    }


    public function __toString() : string
    {
        return inet_ntop($this->m_packedNetwork) . '/' . $this->m_prefixLength;
    }


    /**
     * Clear every bit of a packed address after the first $prefixLength bits.
     *
     * @param string $packed - the packed address, from inet_pton().
     *
     * @param int $prefixLength - how many leading bits to keep.
     *
     * @return string - the packed network address.
     */
    private static function applyMask(string $packed, int $prefixLength) : string
    {
        $masked = '';

        for ($i = 0; $i < strlen($packed); $i++)
        {
            $bitsInThisByte = max(0, min(8, $prefixLength - ($i * 8)));
            $mask = (0xFF << (8 - $bitsInThisByte)) & 0xFF;
            $masked .= chr(ord($packed[$i]) & $mask);
        }

        return $masked;
    }


    # Accessors
    public function getPrefixLength() : int { return $this->m_prefixLength; }
}
