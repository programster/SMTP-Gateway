<?php

/*
 * The sender checks for a "custom" provider: an SMTP server of your own (e.g. mailcow or Postfix) that sends email
 * straight to its recipients. It can't be asked which senders it accepts, so instead this checks what receivers check
 * about the server itself:
 *  - that its address has reverse DNS that resolves back to it ("forward-confirmed"), which Gmail, Yahoo and
 *    Microsoft require,
 *  - that each sender's domain's SPF record lets its address send as the domain, and
 *  - if dkim_selector is set, that each sender's domain publishes the DKIM key it signs with.
 *
 * These assume the server sends from the address its hostname resolves to. One that hands email on to another server
 * (a "smarthost") needs that server checked instead.
 *
 * Unlike the providers asked through an API, its checks look up each sender's DNS as they're made.
 */

declare(strict_types = 1);


final readonly class CustomSenderChecker implements SenderCheckerInterface
{
    private string $m_providerName;
    private string $m_host;
    private DnsResolverInterface $m_dns;
    private string $m_dkimSelector;
    private RelayAddressCollection $m_addresses;
    private string $m_lookupError;


    /**
     * @param string $providerName - the provider's name in settings.yaml.
     *
     * @param string $host - the server's hostname (or address), e.g. "mail.example.org".
     *
     * @param DnsResolverInterface $dns - what to look up the senders' records with.
     *
     * @param string $dkimSelector - the selector the server DKIM-signs with (e.g. mailcow's "dkim"), or an empty
     * string if it isn't known.
     *
     * @param RelayAddressCollection $addresses - the addresses the host resolves to.
     *
     * @param string $lookupError - why the host's addresses couldn't be looked up, or an empty string if they were.
     */
    public function __construct(
        string $providerName,
        string $host,
        DnsResolverInterface $dns,
        string $dkimSelector,
        RelayAddressCollection $addresses,
        string $lookupError
    )
    {
        $this->m_providerName = $providerName;
        $this->m_host = $host;
        $this->m_dns = $dns;
        $this->m_dkimSelector = $dkimSelector;
        $this->m_addresses = $addresses;
        $this->m_lookupError = $lookupError;
    }


    /**
     * Look up the server's addresses and their reverse DNS.
     *
     * @param string $providerName - the provider's name in settings.yaml.
     *
     * @param DnsResolverInterface $dns - what to look things up with.
     *
     * @param string $host - the server's hostname (or address).
     *
     * @param string $dkimSelector - the selector it DKIM-signs with, or an empty string if it isn't known.
     *
     * @return CustomSenderChecker
     */
    public static function fetch(
        string $providerName,
        DnsResolverInterface $dns,
        string $host,
        string $dkimSelector
    ) : CustomSenderChecker
    {
        $addresses = [];
        $error = '';

        try
        {
            $ips = (inet_pton($host) !== false) ? [$host] : $dns->fetchIpAddresses($host);

            foreach ($ips as $ip)
            {
                $addresses[] = RelayAddress::fetch($dns, $ip);
            }
        }
        catch (DnsLookupException $e)
        {
            $error = $e->getMessage();
        }

        $relayAddresses = new RelayAddressCollection(...$addresses);
        return new CustomSenderChecker($providerName, $host, $dns, $dkimSelector, $relayAddresses, $error);
    }


    public function checkAccount() : SenderCheckCollection
    {
        $checks = [];
        $public = $this->getPublicAddresses();

        if ($this->m_lookupError !== '')
        {
            $title = "Can't look up {$this->m_host}";
            $checks[] = $this->createCheck(SenderCheckLevel::WARNING, $title, $this->m_lookupError);
        }
        elseif ($this->m_addresses->count() === 0)
        {
            $detail = "{$this->m_host} has no A or AAAA record, so it can't be connected to.";
            $checks[] = $this->createCheck(SenderCheckLevel::ERROR, "{$this->m_host} doesn't resolve", $detail);
        }
        elseif ($public->count() === 0)
        {
            $checks[] = $this->createPrivateCheck();
        }

        foreach ($public as $address)
        {
            $checks[] = $this->checkReverseDns($address);
        }

        if ($this->m_dkimSelector === '')
        {
            $detail =
                'Set this provider\'s dkim_selector to the selector the server DKIM-signs with (mailcow\'s is "dkim"'
                . ' unless it\'s been changed), so that each sender\'s domain can be checked for its DKIM key.';

            $checks[] = $this->createCheck(SenderCheckLevel::INFO, 'DKIM keys aren\'t checked', $detail);
        }

        return new SenderCheckCollection(...$checks);
    }


    public function checkDomain(DomainDns $dns) : SenderCheckCollection
    {
        $checks = [];

        foreach ($this->checkSpf($dns) as $check)
        {
            $checks[] = $check;
        }

        if ($this->m_dkimSelector !== '')
        {
            $checks[] = $this->checkDkim($dns);
        }

        return new SenderCheckCollection(...$checks);
    }


    public function checkAddress(EmailAddress $address, DomainDns $dns) : SenderCheckCollection
    {
        // SPF and DKIM are both set up for a whole domain, so an address is checked as its domain is.
        return $this->checkDomain($dns);
    }


    public function checkAnySender() : SenderCheckCollection
    {
        $detail =
            "So its senders' domains can't be checked one by one, to see if their SPF and DKIM records let"
            . " {$this->m_host} send as them. Limiting the key's allowed senders to the domains that do means each one"
            . ' is checked here.';

        $check = $this->createCheck(SenderCheckLevel::INFO, 'The key can send as anyone', $detail);
        return new SenderCheckCollection($check);
    }


    /**
     * Check a public address's reverse DNS.
     *
     * @param RelayAddress $address - the address.
     *
     * @return SenderCheck
     */
    private function checkReverseDns(RelayAddress $address) : SenderCheck
    {
        $ip = $address->getIp();

        $why =
            'Gmail, Yahoo and Microsoft refuse or spam email from a server whose address has no reverse DNS (PTR)'
            . ' name that resolves back to it. Ask whoever gives you the address (e.g. your hosting provider) to set'
            . " its PTR to {$this->m_host}, which should resolve to {$ip}.";

        if (count($address->getPtrNames()) === 0)
        {
            $check = $this->createCheck(SenderCheckLevel::WARNING, "{$ip} has no reverse DNS", $why);
        }
        elseif ($address->getConfirmedName() === '')
        {
            $names = implode(', ', $address->getPtrNames());
            $detail = "{$ip}'s reverse DNS name ({$names}) doesn't resolve back to it. {$why}";
            $check = $this->createCheck(SenderCheckLevel::WARNING, "{$ip}'s reverse DNS doesn't match", $detail);
        }
        else
        {
            $name = $address->getConfirmedName();
            $detail = "{$ip}'s reverse DNS name is {$name}, which resolves back to it, as receivers require.";
            $check = $this->createCheck(SenderCheckLevel::OK, "{$ip} has matching reverse DNS", $detail);
        }

        return $check;
    }


    /**
     * Check that a sender's domain's SPF record lets each of the server's public addresses send as it.
     *
     * @param DomainDns $dns - the domain's records.
     *
     * @return SenderCheckCollection
     */
    private function checkSpf(DomainDns $dns) : SenderCheckCollection
    {
        $checks = [];
        $domain = $dns->getDomain();
        $evaluator = new SpfEvaluator($this->m_dns);
        $failLevel = $dns->getDmarcPolicy()->isEnforced() ? SenderCheckLevel::ERROR : SenderCheckLevel::WARNING;

        $brokenDetail =
            'Receivers treat it as failing for every server, e.g. as it needs more than 10 DNS lookups, includes a'
            . ' domain that has no SPF record, or has a typo.';

        $howTo =
            "Add {$this->m_host}'s address to {$domain}'s SPF record (e.g. \"a:{$this->m_host}\" or \"ip4:...\"),"
            . ' unless the server hands its email on to another one, whose address is the one that matters.';

        $public = $this->getPublicAddresses();

        if ($public->count() === 0)
        {
            $title = "Can't check {$domain}'s SPF record";
            $detail = "{$this->m_host}'s public address isn't known (see the provider's own checks).";
            $checks[] = $this->createCheck(SenderCheckLevel::INFO, $title, $detail);
        }

        foreach ($public as $address)
        {
            /* @var $address RelayAddress */
            $ip = $address->getIp();
            $result = $evaluator->evaluate($domain, $ip);

            $title = match ($result) {
                SpfResult::PASS => "SPF lets {$this->m_host} send as {$domain}",
                SpfResult::FAIL => "SPF refuses {$this->m_host} ({$ip})",
                SpfResult::SOFTFAIL, SpfResult::NEUTRAL => "SPF doesn't list {$this->m_host} ({$ip})",
                SpfResult::NONE => "{$domain} has no SPF record",
                SpfResult::PERMERROR => "{$domain}'s SPF record is broken",
                SpfResult::TEMPERROR => "Couldn't look up {$domain}'s SPF record",
                SpfResult::UNKNOWN => "Couldn't work out {$domain}'s SPF record",
            };

            $detail = match ($result) {
                SpfResult::PASS => "{$domain}'s SPF record includes {$ip}, so SPF passes for email sent as it.",
                SpfResult::FAIL, SpfResult::SOFTFAIL, SpfResult::NEUTRAL, SpfResult::NONE => $howTo,
                SpfResult::PERMERROR => $brokenDetail,
                SpfResult::TEMPERROR => 'A DNS lookup it needs failed. Try again later.',
                SpfResult::UNKNOWN => 'It uses a macro (e.g. "%{i}"), which depends on the email being sent.',
            };

            $level = match ($result) {
                SpfResult::PASS => SenderCheckLevel::OK,
                SpfResult::FAIL, SpfResult::PERMERROR => $failLevel,
                SpfResult::SOFTFAIL, SpfResult::NEUTRAL, SpfResult::NONE => SenderCheckLevel::WARNING,
                SpfResult::TEMPERROR, SpfResult::UNKNOWN => SenderCheckLevel::INFO,
            };

            $checks[] = $this->createCheck($level, $title, $detail);
        }

        return new SenderCheckCollection(...$checks);
    }


    /**
     * Check that a sender's domain publishes the DKIM key for dkim_selector.
     *
     * @param DomainDns $dns - the domain's records.
     *
     * @return SenderCheck
     */
    private function checkDkim(DomainDns $dns) : SenderCheck
    {
        $name = "{$this->m_dkimSelector}._domainkey.{$dns->getDomain()}";
        $keys = [];

        try
        {
            foreach ($this->m_dns->fetchTxtRecords($name) as $record)
            {
                if (preg_match('/(^|;)\s*p\s*=\s*([^;]*)/i', $record, $matches) === 1)
                {
                    $keys[] = trim($matches[2]);
                }
            }

            $error = '';
        }
        catch (DnsLookupException $e)
        {
            $error = $e->getMessage();
        }

        if ($error !== '')
        {
            $check = $this->createCheck(SenderCheckLevel::INFO, "Couldn't look up {$name}", $error);
        }
        elseif (count($keys) === 0)
        {
            $detail =
                "There's no DKIM key at {$name}, so receivers can't check the signature {$this->m_host} adds, and DKIM"
                . " fails. Publish the key the server shows for {$dns->getDomain()} (in mailcow: Configuration →"
                . ' ARC/DKIM keys) as a TXT record there.';

            $check = $this->createCheck(SenderCheckLevel::WARNING, "No DKIM key for {$dns->getDomain()}", $detail);
        }
        elseif ($keys[0] === '')
        {
            $detail = "The DKIM record at {$name} has an empty key (p=), which means the key has been revoked.";
            $check = $this->createCheck(SenderCheckLevel::ERROR, 'The DKIM key has been revoked', $detail);
        }
        else
        {
            $detail =
                "{$name} has a DKIM key. This checks it's published, not that {$this->m_host} signs with it: check an"
                . ' email\'s DKIM-Signature header has d=' . $dns->getDomain() . " and s={$this->m_dkimSelector}.";

            $check = $this->createCheck(SenderCheckLevel::OK, "{$dns->getDomain()} publishes a DKIM key", $detail);
        }

        return $check;
    }


    private function createPrivateCheck() : SenderCheck
    {
        $ips = [];

        foreach ($this->m_addresses as $address)
        {
            /* @var $address RelayAddress */
            $ips[] = $address->getIp();
        }

        $detail =
            "{$this->m_host} resolves to a private address (" . implode(', ', $ips) . '), so the address it sends'
            . ' to the internet from isn\'t known here, and its reverse DNS and the senders\' SPF records can\'t be'
            . ' checked against it. Check them against its public address.';

        return $this->createCheck(SenderCheckLevel::INFO, "{$this->m_host} is on a private network", $detail);
    }


    private function getPublicAddresses() : RelayAddressCollection
    {
        $public = [];

        foreach ($this->m_addresses as $address)
        {
            /* @var $address RelayAddress */
            if ($address->isPublic())
            {
                $public[] = $address;
            }
        }

        return new RelayAddressCollection(...$public);
    }


    private function createCheck(SenderCheckLevel $level, string $title, string $detail) : SenderCheck
    {
        return new SenderCheck($level, $this->m_providerName, $title, $detail);
    }


    # Accessors
    public function getProviderName() : string { return $this->m_providerName; }
}
