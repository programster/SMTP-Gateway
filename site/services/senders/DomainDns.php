<?php

/*
 * The DNS records that decide whether receivers trust email from a domain: its DMARC and SPF records, and whether it
 * can receive email itself. Fetch them with DomainDns::fetch().
 *
 * A domain without a DMARC record of its own is covered by its parent's (e.g. mail.example.org by example.org's),
 * using the parent's "sp" (subdomain policy) if it has one. The parents are walked up to the last two labels, which
 * is close enough to DMARC's "organizational domain" without needing the public suffix list.
 */

declare(strict_types = 1);


final readonly class DomainDns
{
    private string $m_domain;
    private string $m_dmarcLocation;
    private array $m_dmarcRecords;
    private DmarcPolicy $m_dmarcPolicy;
    private bool $m_isDkimAlignmentStrict;
    private array $m_spfRecords;
    private bool $m_hasMailHost;


    /**
     * @param string $domain - the domain, e.g. "example.org".
     *
     * @param string $dmarcLocation - the domain the DMARC records were found at (the domain, or a parent of it), or
     * an empty string if none were found.
     *
     * @param list<string> $dmarcRecords - the DMARC records found there. There should only be one: receivers ignore
     * them all if there's more than one.
     *
     * @param list<string> $spfRecords - the domain's SPF records. Again, there should only be one.
     *
     * @param bool $hasMailHost - whether the domain can receive email (it has an MX or address record).
     *
     * @param bool $isUnknown - whether the records couldn't be looked up, so the others are all empty.
     */
    private function __construct(
        string $domain,
        string $dmarcLocation,
        array $dmarcRecords,
        array $spfRecords,
        bool $hasMailHost,
        bool $isUnknown
    )
    {
        $this->m_domain = $domain;
        $this->m_dmarcLocation = $dmarcLocation;
        $this->m_dmarcRecords = $dmarcRecords;
        $this->m_spfRecords = $spfRecords;
        $this->m_hasMailHost = $hasMailHost;
        $tags = (count($dmarcRecords) === 1) ? self::parseDmarcTags($dmarcRecords[0]) : [];
        $isSubdomain = ($dmarcLocation !== '' && $dmarcLocation !== $domain);
        $policyText = ($isSubdomain && isset($tags['sp'])) ? $tags['sp'] : ($tags['p'] ?? '');

        $this->m_dmarcPolicy = match (true) {
            $isUnknown => DmarcPolicy::UNKNOWN,
            $policyText === 'none' => DmarcPolicy::NONE,
            $policyText === 'quarantine' => DmarcPolicy::QUARANTINE,
            $policyText === 'reject' => DmarcPolicy::REJECT,
            default => DmarcPolicy::MISSING,
        };

        $this->m_isDkimAlignmentStrict = (($tags['adkim'] ?? 'r') === 's');
    }


    /**
     * Create the records for a domain whose records couldn't be looked up, so the checks that don't depend on them
     * can still be made. Its DMARC policy is UNKNOWN.
     *
     * @param string $domain - the domain.
     *
     * @return DomainDns
     */
    public static function createUnknown(string $domain) : DomainDns
    {
        return new DomainDns(strtolower($domain), '', [], [], false, true);
    }


    /**
     * Fetch a domain's records.
     *
     * @param DnsResolverInterface $resolver - what to look them up with.
     *
     * @param string $domain - the domain, e.g. "example.org".
     *
     * @return DomainDns
     *
     * @throws DnsLookupException - if a lookup failed.
     */
    public static function fetch(DnsResolverInterface $resolver, string $domain) : DomainDns
    {
        $domain = strtolower($domain);
        $dmarcLocation = '';
        $dmarcRecords = [];
        $candidate = $domain;

        while ($dmarcLocation === '' && substr_count($candidate, '.') >= 1)
        {
            $found = self::filterRecords($resolver->fetchTxtRecords('_dmarc.' . $candidate), 'v=DMARC1');

            if (count($found) > 0)
            {
                $dmarcLocation = $candidate;
                $dmarcRecords = $found;
            }

            $candidate = substr($candidate, strpos($candidate, '.') + 1);
        }

        $spfRecords = self::filterRecords($resolver->fetchTxtRecords($domain), 'v=spf1');
        $hasMailHost = $resolver->fetchHasMailHost($domain);
        return new DomainDns($domain, $dmarcLocation, $dmarcRecords, $spfRecords, $hasMailHost, false);
    }


    /**
     * Get the "organizational domain" DMARC aligns domains by under relaxed alignment: approximated, as for finding
     * a parent's DMARC record, as the last two labels (e.g. "example.org" for "mg.example.org"), without the public
     * suffix list. So two domains under a suffix like "co.uk" are wrongly taken to be one organization's.
     *
     * @param string $domain - the domain.
     *
     * @return string
     */
    public static function getOrganizationalDomain(string $domain) : string
    {
        $labels = explode('.', strtolower(trim($domain, '.')));
        return implode('.', array_slice($labels, -2));
    }


    /**
     * Keep the records of one kind, which start with its version tag (e.g. "v=spf1"), ignoring case. A record with
     * a different tag isn't one of them, and is ignored by receivers.
     *
     * @param list<string> $records - every TXT record at the name.
     *
     * @param string $versionTag - the tag, e.g. "v=spf1".
     *
     * @return list<string>
     */
    private static function filterRecords(array $records, string $versionTag) : array
    {
        $kept = [];

        $pattern = '/^' . preg_quote($versionTag, '/') . '(\s|;|$)/i';

        foreach ($records as $record)
        {
            if (preg_match($pattern, trim($record)) === 1)
            {
                $kept[] = trim($record);
            }
        }

        return $kept;
    }


    /**
     * Read the tags in a DMARC record, e.g. "v=DMARC1; p=reject; adkim=s".
     *
     * @param string $record - the record.
     *
     * @return array<string, string> - tag name to value, both in lowercase.
     */
    private static function parseDmarcTags(string $record) : array
    {
        $tags = [];

        foreach (explode(';', $record) as $part)
        {
            $pair = explode('=', $part, 2);

            if (count($pair) === 2)
            {
                $tags[strtolower(trim($pair[0]))] = strtolower(trim($pair[1]));
            }
        }

        return $tags;
    }


    # Accessors
    public function getDomain() : string { return $this->m_domain; }
    public function getDmarcLocation() : string { return $this->m_dmarcLocation; }
    public function getDmarcRecords() : array { return $this->m_dmarcRecords; }
    public function getDmarcPolicy() : DmarcPolicy { return $this->m_dmarcPolicy; }
    public function isDkimAlignmentStrict() : bool { return $this->m_isDkimAlignmentStrict; }
    public function getSpfRecords() : array { return $this->m_spfRecords; }
    public function hasMailHost() : bool { return $this->m_hasMailHost; }
}
