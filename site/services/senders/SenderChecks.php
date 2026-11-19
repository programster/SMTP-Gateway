<?php

/*
 * Checks whether email from a key's allowed senders will be accepted and trusted: each sender's domain's own DNS
 * (see DomainDnsChecker), and whether each enabled provider is set up to send as it (see SenderCheckerInterface).
 * The web interface shows these on each key's page and the providers page, so a missing piece of setup is found
 * before email starts being refused or going to spam.
 */

declare(strict_types = 1);


final readonly class SenderChecks
{
    private DnsResolverInterface $m_dns;
    private SenderCheckerCollection $m_checkers;


    /**
     * @param DnsResolverInterface $dns - what to look up DNS records with.
     *
     * @param SenderCheckerInterface ...$checkers - a checker for each provider to check.
     */
    public function __construct(DnsResolverInterface $dns, SenderCheckerInterface ...$checkers)
    {
        $this->m_dns = $dns;
        $this->m_checkers = new SenderCheckerCollection(...$checkers);
    }


    /**
     * Create the checks for every enabled provider in settings.yaml, asking each how it's set up. A provider that
     * couldn't be asked is still included, with an error saying why.
     *
     * @return SenderChecks
     *
     * @throws ConfigurationException - if settings.yaml isn't valid.
     */
    public static function fetchForEnabledProviders() : SenderChecks
    {
        $checkers = [];

        foreach (ProviderRegistry::getConfig()->getEnabledProviders() as $provider)
        {
            /* @var $provider ProviderDefinition */
            try
            {
                $checkers[] = $provider->getSettings()->fetchSenderChecker($provider->getName());
            }
            catch (ConfigurationException | ProviderLookupException $e)
            {
                $checkers[] = new FailedSenderChecker($provider->getName(), $e->getMessage());
            }
        }

        return new SenderChecks(new SystemDnsResolver(), ...$checkers);
    }


    /**
     * Check each provider's account (rather than any one sender), e.g. its API key's permissions and its webhook.
     *
     * @return SenderCheckGroupCollection - a group per provider.
     */
    public function checkAccounts() : SenderCheckGroupCollection
    {
        $groups = [];

        foreach ($this->m_checkers as $checker)
        {
            /* @var $checker SenderCheckerInterface */
            $checks = $checker->checkAccount()->toArray();
            $groups[] = new SenderCheckGroup("Provider \"{$checker->getProviderName()}\"", ...$checks);
        }

        return new SenderCheckGroupCollection(...$groups);
    }


    /**
     * Check an email token's allowed senders: each of its rules, or, when it has no sender condition (so may send as
     * anyone), whether each provider can send as any address.
     *
     * @param AddressRestrictionInterface $senders - the allowed senders.
     *
     * @return SenderCheckGroupCollection - a group per rule, or one group for any sender.
     */
    public function checkAllowedSenders(AddressRestrictionInterface $senders) : SenderCheckGroupCollection
    {
        return ($senders instanceof AddressRuleSet)
            ? $this->checkSenders($senders)
            : new SenderCheckGroupCollection($this->checkAnySender());
    }


    /**
     * Check senders.
     *
     * @param AddressRuleSet $senders - the allowed senders, e.g. a key's.
     *
     * @param array<string, list<string>> $usedBy - optionally, the names of the keys that use each rule, by the
     * rule's written form (e.g. "*@example.org"), to add to its heading.
     *
     * @return SenderCheckGroupCollection - a group per rule.
     */
    public function checkSenders(AddressRuleSet $senders, array $usedBy = []) : SenderCheckGroupCollection
    {
        $groups = [];
        $dnsCache = new DomainDnsMap();

        foreach ($senders->getRules() as $rule)
        {
            /* @var $rule AddressRule */
            $names = $usedBy[(string)$rule] ?? [];
            $heading = (count($names) > 0) ? $rule . ' (used by ' . implode(', ', $names) . ')' : (string)$rule;
            $groups[] = new SenderCheckGroup($heading, ...$this->checkDomainRule($rule, $dnsCache)->toArray());
        }

        return new SenderCheckGroupCollection(...$groups);
    }


    /**
     * Check whether each provider can send as any address, for email tokens with no sender condition.
     *
     * @param string ...$usedBy - optionally, the names of the email tokens with no sender condition, to add to its
     * heading.
     *
     * @return SenderCheckGroup
     */
    public function checkAnySender(string ...$usedBy) : SenderCheckGroup
    {
        $checks = [];

        foreach ($this->m_checkers as $checker)
        {
            /* @var $checker SenderCheckerInterface */
            array_push($checks, ...$checker->checkAnySender()->toArray());
        }

        $heading = (count($usedBy) > 0) ? 'Any sender (used by ' . implode(', ', $usedBy) . ')' : 'Any sender';
        return new SenderCheckGroup($heading, ...$checks);
    }


    /**
     * Check a rule for a domain, or for a single address: the domain's DNS, and then each provider.
     *
     * @param AddressRule $rule - the rule, which is for a domain or an address.
     *
     * @param DomainDnsMap $dnsCache - the domains already looked up, which this adds to.
     *
     * @return SenderCheckCollection
     */
    private function checkDomainRule(AddressRule $rule, DomainDnsMap $dnsCache) : SenderCheckCollection
    {
        if ($rule->getKind() === AddressRuleKind::ADDRESS)
        {
            $address = new EmailAddress($rule->getValue());
            $domain = $address->getDomain();

            $checkProvider = function (
                SenderCheckerInterface $checker,
                DomainDns $dns
            ) use ($address) : SenderCheckCollection {
                return $checker->checkAddress($address, $dns);
            };
        }
        else
        {
            $domain = $rule->getValue();

            $checkProvider = function (SenderCheckerInterface $checker, DomainDns $dns) : SenderCheckCollection {
                return $checker->checkDomain($dns);
            };
        }

        try
        {
            $dns = $this->fetchDns($domain, $dnsCache);
            $checks = DomainDnsChecker::check($dns)->toArray();
        }
        catch (DnsLookupException $e)
        {
            $dns = DomainDns::createUnknown($domain);
            $title = "Couldn't look up {$domain}'s DNS";
            $checks = [new SenderCheck(SenderCheckLevel::ERROR, DomainDnsChecker::SOURCE, $title, $e->getMessage())];
        }

        foreach ($this->m_checkers as $checker)
        {
            /* @var $checker SenderCheckerInterface */
            array_push($checks, ...$checkProvider($checker, $dns)->toArray());
        }

        return new SenderCheckCollection(...$checks);
    }


    /**
     * Get a domain's DNS records, from the cache if it's already been looked up.
     *
     * @param string $domain - the domain.
     *
     * @param DomainDnsMap $dnsCache - the domains already looked up, which this adds to.
     *
     * @return DomainDns
     *
     * @throws DnsLookupException - if they couldn't be looked up. Nothing is cached, so the next rule tries again.
     */
    private function fetchDns(string $domain, DomainDnsMap $dnsCache) : DomainDns
    {
        if ($dnsCache->containsKey($domain))
        {
            $dns = $dnsCache->get($domain);
        }
        else
        {
            $dns = DomainDns::fetch($this->m_dns, $domain);
            $dnsCache->put(new DomainDnsEntry($domain, $dns));
        }

        return $dns;
    }
}
