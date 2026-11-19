<?php

/*
 * Works out whether a domain's SPF record lets an address send email as it, following RFC 7208's check_host(): its
 * ip4, ip6, a, mx, include, exists and all mechanisms, the redirect modifier, and the limit of 10 DNS lookups. It
 * doesn't expand macros (e.g. "%{i}"), as they depend on the email being sent, so a record that needs one to be
 * decided gives UNKNOWN. The ptr mechanism, which RFC 7208 says not to use, never matches.
 */

declare(strict_types = 1);


final readonly class SpfEvaluator
{
    // RFC 7208 4.6.4: the most DNS lookups (includes, redirects, a, mx, ptr and exists) a check may make.
    private const int MAX_LOOKUPS = 10;

    // RFC 7208 4.6.4: the most MX hosts an "mx" mechanism looks up.
    private const int MAX_MX_HOSTS = 10;

    private DnsResolverInterface $m_dns;


    /**
     * @param DnsResolverInterface $dns - what to look up the records with.
     */
    public function __construct(DnsResolverInterface $dns)
    {
        $this->m_dns = $dns;
    }


    /**
     * Work out whether a domain's SPF record lets an address send as it.
     *
     * @param string $domain - the domain, e.g. "example.org".
     *
     * @param string $ip - the sending server's IPv4 or IPv6 address.
     *
     * @return SpfResult
     *
     * @throws InvalidInputException - if $ip isn't an IP address.
     */
    public function evaluate(string $domain, string $ip) : SpfResult
    {
        if (inet_pton($ip) === false)
        {
            throw new InvalidInputException("{$ip} isn't an IP address.");
        }

        $lookups = 0;

        try
        {
            $result = $this->checkHost(strtolower($domain), $ip, $lookups);
        }
        catch (DnsLookupException)
        {
            $result = SpfResult::TEMPERROR;
        }

        return $result;
    }


    /**
     * RFC 7208's check_host(): evaluate the SPF record at a domain.
     *
     * @param string $domain - the domain.
     *
     * @param string $ip - the address.
     *
     * @param int $lookups - the DNS lookups made so far, across every include and redirect.
     *
     * @return SpfResult
     *
     * @throws DnsLookupException - if a lookup failed.
     */
    private function checkHost(string $domain, string $ip, int &$lookups) : SpfResult
    {
        $records = [];

        foreach ($this->m_dns->fetchTxtRecords($domain) as $record)
        {
            if (preg_match('/^v=spf1(\s|$)/i', trim($record)) === 1)
            {
                $records[] = trim($record);
            }
        }

        if (count($records) === 0)
        {
            $result = SpfResult::NONE;
        }
        elseif (count($records) > 1)
        {
            $result = SpfResult::PERMERROR;
        }
        else
        {
            $result = $this->evaluateRecord($records[0], $domain, $ip, $lookups);
        }

        return $result;
    }


    /**
     * Evaluate one SPF record's terms in order: the first mechanism that matches gives the result, then a redirect
     * if none did, or NEUTRAL if there's no redirect.
     *
     * @throws DnsLookupException - if a lookup failed.
     */
    private function evaluateRecord(string $record, string $domain, string $ip, int &$lookups) : SpfResult
    {
        $terms = preg_split('/\s+/', trim($record));
        $redirect = '';
        $matched = null;
        $index = 1;

        // Each term is a modifier (name=value), or a mechanism: an optional qualifier, its name, then optionally a
        // ":" and a domain or address, and a CIDR prefix (with "//" before the IPv6 one, for "a" and "mx").
        $mechanismPattern = '/^([+\-~?]?)([a-z0-9]+)(?::([^\/]*))?(?:\/(\d+))?(?:\/\/(\d+))?$/i';

        while ($matched === null && $index < count($terms))
        {
            $term = $terms[$index];
            $index++;
            $isModifier = (preg_match('/^([a-z][a-z0-9_.-]*)=(.*)$/i', $term, $modifier) === 1);

            if ($isModifier && strtolower($modifier[1]) === 'redirect')
            {
                $redirect = strtolower($modifier[2]);
            }
            elseif ($isModifier === false && preg_match($mechanismPattern, $term, $parts) !== 1)
            {
                $matched = SpfResult::PERMERROR;
            }
            elseif ($isModifier === false)
            {
                $match = $this->matchMechanism($parts, $domain, $ip, $lookups);

                $matched = match ($match) {
                    SpfResult::PASS => SpfResult::createFromQualifier($parts[1]),
                    SpfResult::NONE => null,
                    default => $match,
                };
            }
        }

        if ($matched !== null)
        {
            $result = $matched;
        }
        elseif ($redirect !== '')
        {
            $lookups++;
            $isOverLimit = ($lookups > self::MAX_LOOKUPS);
            $redirected = $isOverLimit ? SpfResult::PERMERROR : $this->checkHost($redirect, $ip, $lookups);
            $result = ($redirected === SpfResult::NONE) ? SpfResult::PERMERROR : $redirected;
        }
        else
        {
            $result = SpfResult::NEUTRAL;
        }

        return $result;
    }


    /**
     * Check whether one mechanism matches the address.
     *
     * @param array $parts - the mechanism, as matched in evaluateRecord(): its qualifier, name, target (the domain or
     * address after the colon), CIDR prefix, and IPv6 CIDR prefix, each an empty string (or missing) if not given.
     *
     * @param string $domain - the domain whose record it's in.
     *
     * @param string $ip - the address.
     *
     * @param int $lookups - the DNS lookups made so far.
     *
     * @return SpfResult - PASS if it matches, NONE if it doesn't, or the error (or UNKNOWN) that ends the check.
     *
     * @throws DnsLookupException - if a lookup failed.
     */
    private function matchMechanism(array $parts, string $domain, string $ip, int &$lookups) : SpfResult
    {
        $mechanism = strtolower($parts[2]);
        $target = $parts[3] ?? '';
        $cidr = $parts[4] ?? '';
        $ip6Cidr = $parts[5] ?? '';
        $isIp6 = str_contains($ip, ':');
        $prefix = $isIp6 ? (($ip6Cidr !== '') ? (int)$ip6Cidr : 128) : (($cidr !== '') ? (int)$cidr : 32);
        $range = ($cidr === '') ? $target : "{$target}/{$cidr}";
        $isLookup = in_array($mechanism, ['include', 'a', 'mx', 'ptr', 'exists'], true);
        $lookups += $isLookup ? 1 : 0;
        $name = ($target === '') ? $domain : strtolower($target);

        if ($lookups > self::MAX_LOOKUPS)
        {
            $result = SpfResult::PERMERROR;
        }
        elseif (str_contains($target, '%'))
        {
            $result = SpfResult::UNKNOWN;
        }
        elseif ($mechanism === 'all')
        {
            $result = SpfResult::PASS;
        }
        elseif (($mechanism === 'ip4' || $mechanism === 'ip6') && $target === '')
        {
            $result = SpfResult::PERMERROR;
        }
        elseif ($mechanism === 'ip4' || $mechanism === 'ip6')
        {
            $result = self::isInRange($ip, $range) ? SpfResult::PASS : SpfResult::NONE;
        }
        elseif ($mechanism === 'a')
        {
            $result = $this->matchAddresses($this->m_dns->fetchIpAddresses($name), $ip, $prefix);
        }
        elseif ($mechanism === 'mx')
        {
            $addresses = [];

            foreach (array_slice($this->m_dns->fetchMxHosts($name), 0, self::MAX_MX_HOSTS) as $host)
            {
                $addresses = [...$addresses, ...$this->m_dns->fetchIpAddresses($host)];
            }

            $result = $this->matchAddresses($addresses, $ip, $prefix);
        }
        elseif ($mechanism === 'include' && $target === '')
        {
            $result = SpfResult::PERMERROR;
        }
        elseif ($mechanism === 'include')
        {
            $included = $this->checkHost($name, $ip, $lookups);

            $result = match ($included) {
                SpfResult::PASS => SpfResult::PASS,
                SpfResult::FAIL, SpfResult::SOFTFAIL, SpfResult::NEUTRAL => SpfResult::NONE,
                SpfResult::NONE => SpfResult::PERMERROR,
                default => $included,
            };
        }
        elseif ($mechanism === 'exists')
        {
            $result = (count($this->m_dns->fetchIpAddresses($name)) > 0) ? SpfResult::PASS : SpfResult::NONE;
        }
        elseif ($mechanism === 'ptr')
        {
            $result = SpfResult::NONE;
        }
        else
        {
            $result = SpfResult::PERMERROR;
        }

        return $result;
    }


    /**
     * Check whether an address is in any of the networks around some addresses (an "a" or "mx" mechanism).
     *
     * @param list<string> $addresses - the addresses the mechanism's name resolved to.
     *
     * @param string $ip - the sending address.
     *
     * @param int $prefix - the prefix length of the network around each.
     *
     * @return SpfResult - PASS or NONE.
     */
    private function matchAddresses(array $addresses, string $ip, int $prefix) : SpfResult
    {
        $isMatch = false;

        foreach ($addresses as $address)
        {
            $isMatch = $isMatch || self::isInRange($ip, "{$address}/{$prefix}");
        }

        return $isMatch ? SpfResult::PASS : SpfResult::NONE;
    }


    /**
     * Check whether an address is in a network, e.g. "192.0.2.0/24", or is a single address. An address of the
     * other family (IPv4 against an IPv6 network) is never in it.
     *
     * @param string $ip - the address.
     *
     * @param string $range - the network, or a single address.
     *
     * @return bool
     */
    private static function isInRange(string $ip, string $range) : bool
    {
        $parts = explode('/', $range, 2);
        $network = inet_pton($parts[0]);
        $address = inet_pton($ip);
        $bits = ($network === false) ? 0 : strlen($network) * 8;
        $prefix = isset($parts[1]) ? (int)$parts[1] : $bits;
        $isInRange = false;

        if ($network !== false && $address !== false && strlen($network) === strlen($address) && $prefix <= $bits)
        {
            $wholeBytes = intdiv($prefix, 8);
            $remainingBits = $prefix % 8;
            $isInRange = (substr($network, 0, $wholeBytes) === substr($address, 0, $wholeBytes));

            if ($isInRange && $remainingBits > 0)
            {
                $mask = (0xFF << (8 - $remainingBits)) & 0xFF;
                $isInRange = ((ord($network[$wholeBytes]) & $mask) === (ord($address[$wholeBytes]) & $mask));
            }
        }

        return $isInRange;
    }
}
