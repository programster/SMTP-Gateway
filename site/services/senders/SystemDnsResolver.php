<?php

/*
 * Looks up DNS records through the system's resolver (PHP's dns_get_record()).
 */

declare(strict_types = 1);


final readonly class SystemDnsResolver implements DnsResolverInterface
{
    public function fetchTxtRecords(string $name) : array
    {
        $records = [];

        foreach (self::fetchRecords($name, DNS_TXT) as $record)
        {
            if (isset($record['txt']) && is_string($record['txt']))
            {
                $records[] = $record['txt'];
            }
        }

        return $records;
    }


    public function fetchHasMailHost(string $domain) : bool
    {
        $mxRecords = self::fetchRecords($domain, DNS_MX);
        $isNullMx = false;

        foreach ($mxRecords as $record)
        {
            // A "null MX" (RFC 7505), with "." as its target, says the domain receives no email at all.
            $target = rtrim((string)($record['target'] ?? ''), '.');
            $isNullMx = $isNullMx || ($target === '');
        }

        if ($isNullMx)
        {
            $hasMailHost = false;
        }
        elseif (count($mxRecords) > 0)
        {
            $hasMailHost = true;
        }
        else
        {
            $hasMailHost = (count(self::fetchRecords($domain, DNS_A | DNS_AAAA)) > 0);
        }

        return $hasMailHost;
    }


    public function fetchIpAddresses(string $name) : array
    {
        $addresses = [];

        foreach (self::fetchRecords($name, DNS_A | DNS_AAAA) as $record)
        {
            $address = $record['ip'] ?? ($record['ipv6'] ?? '');

            if (is_string($address) && $address !== '')
            {
                $addresses[] = $address;
            }
        }

        return $addresses;
    }


    public function fetchMxHosts(string $domain) : array
    {
        $records = self::fetchRecords($domain, DNS_MX);
        $preferences = [];

        foreach ($records as $record)
        {
            $target = rtrim((string)($record['target'] ?? ''), '.');

            if ($target !== '')
            {
                $preferences[$target] = (int)($record['pri'] ?? 0);
            }
        }

        asort($preferences);
        return array_map('strval', array_keys($preferences));
    }


    public function fetchPtrNames(string $ip) : array
    {
        $names = [];
        $packed = inet_pton($ip);

        if ($packed === false)
        {
            throw new DnsLookupException("{$ip} isn't an IP address.");
        }

        if (strlen($packed) === 4)
        {
            $reverse = implode('.', array_reverse(explode('.', $ip))) . '.in-addr.arpa';
        }
        else
        {
            $reverse = implode('.', array_reverse(str_split(bin2hex($packed)))) . '.ip6.arpa';
        }

        foreach (self::fetchRecords($reverse, DNS_PTR) as $record)
        {
            $target = rtrim((string)($record['target'] ?? ''), '.');

            if ($target !== '')
            {
                $names[] = $target;
            }
        }

        return $names;
    }


    /**
     * Fetch the records of some types at a name.
     *
     * @param string $name - the name.
     *
     * @param int $types - the DNS_* types, e.g. DNS_TXT.
     *
     * @return array - the records, as dns_get_record() gives them: an empty array if there are none.
     *
     * @throws DnsLookupException - if the lookup failed (dns_get_record() returns an empty array when the name
     * doesn't exist, and false only when the lookup itself failed).
     */
    private static function fetchRecords(string $name, int $types) : array
    {
        // Its warning is turned into this exception's message instead.
        $records = @dns_get_record($name, $types);

        if ($records === false)
        {
            $reason = error_get_last()['message'] ?? 'no answer';
            throw new DnsLookupException("Looking up {$name} in DNS failed: {$reason}");
        }

        return $records;
    }
}
