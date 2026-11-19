<?php

/*
 * Checks a key's senders against what a provider has verified, for the providers that verify senders the same way:
 * by authenticating a whole domain (DNS records that let it DKIM-sign email as the domain), and, for some, by
 * verifying one address on its own (clicking a link emailed to it). Only domain authentication makes the email pass
 * DMARC: with just the address verified, the provider signs it as one of its own domains, which doesn't match the
 * sender's.
 *
 * Each provider's SenderCheckerInterface reads its domains and addresses from its API, and hands them to this, with
 * a ProviderProfile so the checks are described in the provider's own terms.
 */

declare(strict_types = 1);


final readonly class IdentityChecks
{
    private string $m_providerName;
    private ProviderProfile $m_profile;
    private bool $m_canReadDomains;
    private AuthenticatedDomainCollection $m_domains;
    private bool $m_canReadAddresses;
    private VerifiedAddressCollection $m_addresses;


    /**
     * @param string $providerName - the provider's name in settings.yaml (each check's source).
     *
     * @param ProviderProfile $profile - how the provider verifies senders, and what it calls things.
     *
     * @param bool $canReadDomains - whether its authenticated domains could be listed.
     *
     * @param AuthenticatedDomainCollection $domains - its authenticated domains (empty if they couldn't be listed).
     *
     * @param bool $canReadAddresses - whether its verified single addresses could be listed. True for a provider
     * that has none (see ProviderProfile::hasSingleSenders()), as there's nothing it couldn't see.
     *
     * @param VerifiedAddressCollection $addresses - its verified single addresses (empty if it has none, or they
     * couldn't be listed).
     */
    public function __construct(
        string $providerName,
        ProviderProfile $profile,
        bool $canReadDomains,
        AuthenticatedDomainCollection $domains,
        bool $canReadAddresses,
        VerifiedAddressCollection $addresses
    )
    {
        $this->m_providerName = $providerName;
        $this->m_profile = $profile;
        $this->m_canReadDomains = $canReadDomains;
        $this->m_domains = $domains;
        $this->m_canReadAddresses = $canReadAddresses;
        $this->m_addresses = $addresses;
    }


    /**
     * Check whether the provider can send as any address at a domain (a "*@example.org" rule).
     *
     * @param DomainDns $dns - the domain's records.
     *
     * @return SenderCheckCollection
     */
    public function checkDomain(DomainDns $dns) : SenderCheckCollection
    {
        $checks = [];
        $domain = $dns->getDomain();
        $label = $this->m_profile->getLabel();
        $covering = $this->findCoveringDomains($domain);
        $addressesThere = $this->findAddressesAtDomain($domain);

        if ($covering->count() > 0)
        {
            $checks[] = $this->describeAuthentication($covering->first(), $dns);
        }
        elseif ($this->m_canReadDomains === false)
        {
            $detail = 'The API key isn\'t allowed to list authenticated domains (see the provider\'s own checks).';
            $checks[] = $this->createCheck(SenderCheckLevel::WARNING, "Can't check {$domain}", $detail);
        }
        elseif (count($addressesThere) > 0)
        {
            $detail =
                "{$domain} isn't authenticated with {$label}, and only these addresses at it are verified: "
                . implode(', ', $addressesThere) . ". {$label} refuses email from any other address at it, and even"
                . " these fail DMARC, as {$label} signs them as {$this->m_profile->getSingleSenderSignsAs()}. "
                . $this->describeAuthenticating($domain);

            $title = "Only some addresses at {$domain} can send";
            $checks[] = $this->createCheck(SenderCheckLevel::ERROR, $title, $detail);
        }
        else
        {
            $checks[] = $this->createNotVerified($domain, $dns);
        }

        return new SenderCheckCollection(...$checks);
    }


    /**
     * Check whether the provider can send as one address.
     *
     * @param EmailAddress $address - the address.
     *
     * @param DomainDns $dns - the records of its domain.
     *
     * @return SenderCheckCollection
     */
    public function checkAddress(EmailAddress $address, DomainDns $dns) : SenderCheckCollection
    {
        $checks = [];
        $covering = $this->findCoveringDomains($dns->getDomain());
        $verified = $this->findAddress($address);
        $isValidlyCovered = ($covering->count() > 0 && $covering->first()->isValid());

        if ($covering->count() > 0)
        {
            $checks[] = $this->describeAuthentication($covering->first(), $dns);
        }

        if ($isValidlyCovered === false && $verified->count() > 0)
        {
            $checks[] = $this->describeSingleAddress($verified->first(), $dns);
        }
        elseif ($isValidlyCovered === false && $covering->count() === 0)
        {
            $canCheckAll = ($this->m_canReadDomains && $this->m_canReadAddresses);

            $result = $canCheckAll
                ? $this->createNotVerified((string)$address, $dns)
                : $this->createCheck(
                    SenderCheckLevel::WARNING,
                    "Can't check {$address}",
                    'The API key isn\'t allowed to list everything this needs (see the provider\'s own checks).'
                );

            $checks[] = $result;
        }

        return new SenderCheckCollection(...$checks);
    }


    /**
     * Describe a key that may send as anyone (a "*" rule), whose senders can't be checked one by one, listing the
     * senders the provider has verified.
     *
     * @return SenderCheckCollection
     */
    public function checkAnySender() : SenderCheckCollection
    {
        $verified = [];
        $label = $this->m_profile->getLabel();

        foreach ($this->m_domains as $domain)
        {
            /* @var $domain AuthenticatedDomain */
            if ($domain->isValid())
            {
                $verified[] = '*@' . $domain->getDomain();
            }
        }

        foreach ($this->m_addresses as $address)
        {
            /* @var $address VerifiedAddress */
            if ($address->isVerified())
            {
                $verified[] = $address->getAddress();
            }
        }

        $list = (count($verified) > 0) ? implode(', ', $verified) : 'none that could be listed';
        $isRefused = ($this->m_profile->getUnverifiedHandling() === UnverifiedSenderHandling::REFUSED);

        $what = $isRefused
            ? "{$label} refuses email from senders it hasn't verified, and these are the ones it has: {$list}."
            : "{$label} only DKIM-signs email as the domains authenticated with it, which are: {$list}.";

        $outcome = $isRefused ? 'would refuse' : 'would send unsigned';

        $detail =
            "So its senders can't be checked one by one. {$what} Limiting the key's allowed senders to those means a"
            . " sender {$label} {$outcome} is caught here instead, with a clear error.";

        $check = $this->createCheck(SenderCheckLevel::WARNING, 'The key can send as anyone', $detail);
        return new SenderCheckCollection($check);
    }


    /**
     * Describe how a domain's authentication covers a sender's domain.
     *
     * @param AuthenticatedDomain $authenticated - the authenticated domain that covers it (it, or a parent of it).
     *
     * @param DomainDns $dns - the sender's domain's records.
     *
     * @return SenderCheck
     */
    private function describeAuthentication(AuthenticatedDomain $authenticated, DomainDns $dns) : SenderCheck
    {
        $name = $authenticated->getDomain();
        $label = $this->m_profile->getLabel();
        $isExact = ($name === $dns->getDomain());
        $lastChecked = $authenticated->getLastCheckedAt();
        $when = ($lastChecked > 0) ? ' when it last checked (' . gmdate('j M Y H:i', $lastChecked) . ' UTC)' : '';

        if ($authenticated->isValid() === false)
        {
            $missing = $authenticated->getInvalidRecords();

            $records = (count($missing) > 0)
                ? "{$label} didn't find these DNS records{$when}: " . implode('; ', $missing) . '.'
                : "{$label} hasn't been able to verify its DNS records{$when}.";

            $isSentAnyway = ($this->m_profile->getUnverifiedHandling() === UnverifiedSenderHandling::SENT_UNALIGNED);
            $unlessSingle = $this->m_profile->hasSingleSenders() ? ', unless the address is verified on its own' : '';
            $until = $isSentAnyway ? 'its email fails DMARC.' : "{$label} refuses email from it{$unlessSingle}.";

            $detail =
                "{$records} Add them, then have {$label} check {$name} again ({$this->m_profile->getDomainSettings()})."
                . " Until then, {$until}";

            $check = $this->createCheck(SenderCheckLevel::ERROR, "{$name}'s authentication isn't finished", $detail);
        }
        elseif ($isExact)
        {
            $detail = "{$label} DKIM-signs its email as {$name}, so it passes DMARC.";
            $check = $this->createCheck(SenderCheckLevel::OK, "{$name} is authenticated with {$label}", $detail);
        }
        elseif ($dns->isDkimAlignmentStrict())
        {
            $detail =
                "{$label} signs its email as {$name}, but {$dns->getDomain()}'s DMARC record asks for strict DKIM"
                . " alignment (adkim=s), which only an exact match passes. Authenticate {$dns->getDomain()} itself,"
                . ' or relax the alignment.';

            $title = "Only {$name} is authenticated, not {$dns->getDomain()}";
            $check = $this->createCheck(SenderCheckLevel::WARNING, $title, $detail);
        }
        else
        {
            $detail =
                "{$label} DKIM-signs its email as {$name}, which passes DMARC for {$dns->getDomain()}, as DMARC"
                . ' accepts a parent domain unless its record asks for strict alignment.';

            $check = $this->createCheck(SenderCheckLevel::OK, "Covered by {$name}'s authentication", $detail);
        }

        return $check;
    }


    /**
     * Describe a sender that's only verified as a single address, without its domain being authenticated.
     *
     * @param VerifiedAddress $verified - the address.
     *
     * @param DomainDns $dns - the records of its domain.
     *
     * @return SenderCheck
     */
    private function describeSingleAddress(VerifiedAddress $verified, DomainDns $dns) : SenderCheck
    {
        $address = $verified->getAddress();
        $label = $this->m_profile->getLabel();
        $domain = $dns->getDomain();
        $policy = $dns->getDmarcPolicy();
        $outcome = ($policy === DmarcPolicy::REJECT) ? 'refuse it' : 'put it in spam';

        $why =
            "{$label} accepts it, but signs it as {$this->m_profile->getSingleSenderSignsAs()} rather than {$domain},"
            . ' so it fails DMARC. ' . $this->describeAuthenticating($domain);

        if ($verified->isVerified() === false)
        {
            $detail =
                "{$address} has been added as a single sender in {$label}, but the link in the email {$label} sent"
                . " it hasn't been clicked yet, so {$label} refuses email from it.";

            $check = $this->createCheck(SenderCheckLevel::ERROR, "{$address} isn't verified yet", $detail);
        }
        elseif ($policy->isEnforced())
        {
            $detail = "{$why} {$domain}'s DMARC policy is p={$policy->value}, so receivers will {$outcome}.";
            $check = $this->createCheck(SenderCheckLevel::ERROR, 'Only the address is verified', $detail);
        }
        else
        {
            $detail = "{$why} That makes it likelier to go to spam, and Gmail and Yahoo require bulk senders to pass.";
            $check = $this->createCheck(SenderCheckLevel::WARNING, 'Only the address is verified', $detail);
        }

        return $check;
    }


    /**
     * Create the check for a sender the provider hasn't verified at all.
     *
     * @param string $sender - the sender, e.g. "*@example.org" or "someone@example.org".
     *
     * @param DomainDns $dns - the records of its domain.
     *
     * @return SenderCheck
     */
    private function createNotVerified(string $sender, DomainDns $dns) : SenderCheck
    {
        $label = $this->m_profile->getLabel();
        $refusal = $this->m_profile->getRefusalMessage();
        $quoted = ($refusal === '') ? '' : " (\"{$refusal}\")";
        $howTo = $this->describeAuthenticating($dns->getDomain());
        $policy = $dns->getDmarcPolicy();

        if ($this->m_profile->getUnverifiedHandling() === UnverifiedSenderHandling::REFUSED)
        {
            $detail = "{$label} refuses email from senders it hasn't verified{$quoted}. {$howTo}";
            $check = $this->createCheck(SenderCheckLevel::ERROR, "{$label} hasn't verified {$sender}", $detail);
        }
        else
        {
            $refused = " As its DMARC policy is p={$policy->value}, receivers won't accept it.";
            $outcome = $policy->isEnforced() ? $refused : '';

            $detail =
                "{$label} still sends it, but can't DKIM-sign it as {$dns->getDomain()}, so it fails DMARC, and is"
                . " likely to go to spam.{$outcome} {$howTo}";

            $level = $policy->isEnforced() ? SenderCheckLevel::ERROR : SenderCheckLevel::WARNING;
            $check = $this->createCheck($level, "{$label} hasn't authenticated {$sender}", $detail);
        }

        return $check;
    }


    /**
     * Describe how to authenticate a domain with the provider.
     *
     * @param string $domain - the domain.
     *
     * @return string
     */
    private function describeAuthenticating(string $domain) : string
    {
        $label = $this->m_profile->getLabel();
        $where = $this->m_profile->getDomainSettings();
        $ownDomain = "Authenticate {$domain} in {$label} ({$where}), and add its DNS records.";
        $freeDomain = "{$domain} can't be authenticated, as only its owner controls its DNS. Use a domain you own.";
        return DomainDnsChecker::isFreeMailDomain($domain) ? $freeDomain : $ownDomain;
    }


    /**
     * Find the authenticated domains that cover a sender's domain, best first: the domain itself before a parent of
     * it, and a valid one before one that isn't.
     *
     * @param string $domain - the sender's domain.
     *
     * @return AuthenticatedDomainCollection
     */
    private function findCoveringDomains(string $domain) : AuthenticatedDomainCollection
    {
        $scored = [];

        foreach ($this->m_domains as $index => $authenticated)
        {
            /* @var $authenticated AuthenticatedDomain */
            if ($authenticated->covers($domain))
            {
                $score = (($authenticated->getDomain() === $domain) ? 2 : 0) + ($authenticated->isValid() ? 1 : 0);
                $scored[$index] = $score * 1000 + strlen($authenticated->getDomain());
            }
        }

        arsort($scored);
        $covering = [];

        foreach (array_keys($scored) as $index)
        {
            $covering[] = $this->m_domains[$index];
        }

        return new AuthenticatedDomainCollection(...$covering);
    }


    /**
     * Find the verified single address for an address.
     *
     * @param EmailAddress $address - the address.
     *
     * @return VerifiedAddressCollection - it, or empty if there isn't one. A verified one comes first.
     */
    private function findAddress(EmailAddress $address) : VerifiedAddressCollection
    {
        $verified = [];
        $unverified = [];

        foreach ($this->m_addresses as $candidate)
        {
            /* @var $candidate VerifiedAddress */
            if ($candidate->isAddress($address) && $candidate->isVerified())
            {
                $verified[] = $candidate;
            }
            elseif ($candidate->isAddress($address))
            {
                $unverified[] = $candidate;
            }
        }

        return new VerifiedAddressCollection(...$verified, ...$unverified);
    }


    /**
     * Find the verified single addresses at a domain.
     *
     * @param string $domain - the domain.
     *
     * @return list<string> - the addresses.
     */
    private function findAddressesAtDomain(string $domain) : array
    {
        $addresses = [];

        foreach ($this->m_addresses as $candidate)
        {
            /* @var $candidate VerifiedAddress */
            if ($candidate->isVerified() && $candidate->isAtDomain($domain))
            {
                $addresses[] = $candidate->getAddress();
            }
        }

        return $addresses;
    }


    private function createCheck(SenderCheckLevel $level, string $title, string $detail) : SenderCheck
    {
        return new SenderCheck($level, $this->m_providerName, $title, $detail);
    }
}
