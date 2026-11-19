<?php

/*
 * The sender checks for a Mailgun provider. Mailgun doesn't verify senders: it sends whatever it's given, and
 * DKIM-signs it as the domain whose SMTP credentials it was sent with. So what matters is whether that domain
 * aligns with each sender's (it's the same domain, or, unless DMARC asks for strict alignment, the same
 * organization's, e.g. mg.example.org for example.org), so the email passes DMARC.
 *
 * With an api_key, it also checks the domain is verified, active and not a sandbox domain, and that Mailgun's
 * webhooks post here. Everything is read with GET requests, when it's created (see fetch()).
 */

declare(strict_types = 1);


final readonly class MailgunSenderChecker implements SenderCheckerInterface
{
    // The webhooks whose events are recorded against each email, and so should post here.
    private const array RECORDED_WEBHOOKS = [
        'delivered', 'permanent_fail', 'temporary_fail', 'complained', 'unsubscribed', 'opened', 'clicked',
    ];

    // Of those, the ones without which bounces and deliveries go unrecorded.
    private const array ESSENTIAL_WEBHOOKS = ['delivered', 'permanent_fail', 'temporary_fail'];

    private string $m_providerName;
    private string $m_domain;
    private MailgunDomainState $m_state;
    private array $m_invalidRecords;
    private string $m_disabledReason;
    private bool $m_canReadWebhooks;
    private array $m_webhookEvents;
    private bool $m_isWebhookKeySet;
    private string $m_webhookUrl;


    /**
     * @param string $providerName - the provider's name in settings.yaml.
     *
     * @param string $domain - the domain it logs in as (and so signs as), e.g. "mg.example.org".
     *
     * @param MailgunDomainState $state - what Mailgun's API says about the domain.
     *
     * @param list<string> $invalidRecords - the domain's DNS records Mailgun didn't find, described, e.g. "TXT
     * mx._domainkey.mg.example.org".
     *
     * @param string $disabledReason - why Mailgun has disabled the domain, if it's DISABLED and says why.
     *
     * @param bool $canReadWebhooks - whether the domain's webhooks could be listed.
     *
     * @param list<string> $webhookEvents - the webhooks (e.g. "delivered") that post to $webhookUrl.
     *
     * @param bool $isWebhookKeySet - whether the provider's webhook_signing_key is set.
     *
     * @param string $webhookUrl - where Mailgun should send its events, or an empty string if PUBLIC_URL isn't set.
     */
    public function __construct(
        string $providerName,
        string $domain,
        MailgunDomainState $state,
        array $invalidRecords,
        string $disabledReason,
        bool $canReadWebhooks,
        array $webhookEvents,
        bool $isWebhookKeySet,
        string $webhookUrl
    )
    {
        $this->m_providerName = $providerName;
        $this->m_domain = strtolower($domain);
        $this->m_state = $state;
        $this->m_invalidRecords = $invalidRecords;
        $this->m_disabledReason = $disabledReason;
        $this->m_canReadWebhooks = $canReadWebhooks;
        $this->m_webhookEvents = $webhookEvents;
        $this->m_isWebhookKeySet = $isWebhookKeySet;
        $this->m_webhookUrl = $webhookUrl;
    }


    /**
     * Read the domain and its webhooks from Mailgun's API.
     *
     * @param string $providerName - the provider's name in settings.yaml.
     *
     * @param string $domain - the domain it logs in as.
     *
     * @param JsonApiClient|null $api - Mailgun's API, with the provider's api_key (see createApiClient()), or null
     * if it has none, in which case only the senders' alignment with the domain is checked.
     *
     * @param bool $isWebhookKeySet - whether the provider's webhook_signing_key is set.
     *
     * @param string $webhookUrl - where Mailgun should send its events, or an empty string if that isn't known.
     *
     * @return MailgunSenderChecker
     *
     * @throws ProviderLookupException - if Mailgun couldn't be reached, or rejected the API key.
     */
    public static function fetch(
        string $providerName,
        string $domain,
        ?JsonApiClient $api,
        bool $isWebhookKeySet,
        string $webhookUrl
    ) : MailgunSenderChecker
    {
        $state = MailgunDomainState::UNCHECKED;
        $invalidRecords = [];
        $disabledReason = '';
        $canReadWebhooks = false;
        $webhookEvents = [];

        if ($api !== null)
        {
            try
            {
                $details = $api->fetchJson('/v4/domains/' . rawurlencode($domain));
                $info = is_array($details['domain'] ?? null) ? $details['domain'] : [];
                $state = MailgunDomainState::createFromApi($info);
                $invalidRecords = self::describeInvalidRecords($details['sending_dns_records'] ?? []);
                $disabled = is_array($info['disabled'] ?? null) ? $info['disabled'] : [];
                $disabledReason = trim((string)($disabled['reason'] ?? '') . ' ' . (string)($disabled['note'] ?? ''));
            }
            catch (ProviderNotFoundException)
            {
                $state = MailgunDomainState::MISSING;
            }
            catch (ProviderPermissionException)
            {
                $state = MailgunDomainState::UNREADABLE;
            }

            try
            {
                $path = '/v3/domains/' . rawurlencode($domain) . '/webhooks';
                $webhooks = $api->fetchJson($path)['webhooks'] ?? [];
                $webhookEvents = self::findEventsPostingTo(is_array($webhooks) ? $webhooks : [], $webhookUrl);
                $canReadWebhooks = true;
            }
            catch (ProviderPermissionException | ProviderNotFoundException)
            {
                $canReadWebhooks = false;
            }
        }

        return new MailgunSenderChecker(
            $providerName,
            $domain,
            $state,
            $invalidRecords,
            $disabledReason,
            $canReadWebhooks,
            $webhookEvents,
            $isWebhookKeySet,
            $webhookUrl
        );
    }


    /**
     * Create a client for Mailgun's API.
     *
     * @param MailgunRegion $region - the region the account's domains are in.
     *
     * @param string $apiKey - the API key.
     *
     * @return JsonApiClient
     */
    public static function createApiClient(MailgunRegion $region, string $apiKey) : JsonApiClient
    {
        $authorization = 'Basic ' . base64_encode("api:{$apiKey}");
        return JsonApiClient::create('Mailgun', $region->getApiUrl(), ['Authorization' => $authorization]);
    }


    public function checkAccount() : SenderCheckCollection
    {
        return new SenderCheckCollection($this->checkDomainState(), ...$this->checkWebhooks()->toArray());
    }


    public function checkDomain(DomainDns $dns) : SenderCheckCollection
    {
        return new SenderCheckCollection($this->checkAlignment($dns));
    }


    public function checkAddress(EmailAddress $address, DomainDns $dns) : SenderCheckCollection
    {
        // Mailgun signs every address as the same domain, so an address is checked as its domain is.
        return $this->checkDomain($dns);
    }


    public function checkAnySender() : SenderCheckCollection
    {
        $organization = DomainDns::getOrganizationalDomain($this->m_domain);

        $detail =
            "Mailgun sends email from any sender, but signs it all as {$this->m_domain}, so only senders at"
            . " {$organization} (or its subdomains) pass DMARC. Limiting the key's allowed senders to those means"
            . ' any other sender is caught here instead.';

        $check = $this->createCheck(SenderCheckLevel::WARNING, 'The key can send as anyone', $detail);
        return new SenderCheckCollection($check);
    }


    /**
     * Check a sender's domain aligns with the domain Mailgun signs as, so its email passes DMARC.
     *
     * @param DomainDns $dns - the sender's domain's records.
     *
     * @return SenderCheck
     */
    private function checkAlignment(DomainDns $dns) : SenderCheck
    {
        $domain = $dns->getDomain();
        $signer = $this->m_domain;
        $organization = DomainDns::getOrganizationalDomain($domain);
        $isSameOrganization = ($organization === DomainDns::getOrganizationalDomain($signer));
        $policy = $dns->getDmarcPolicy();

        if ($domain === $signer)
        {
            $detail = "Mailgun DKIM-signs its email as {$signer}, so it passes DMARC.";
            $check = $this->createCheck(SenderCheckLevel::OK, "Signed as {$domain}", $detail);
        }
        elseif ($isSameOrganization && $dns->isDkimAlignmentStrict())
        {
            $detail =
                "Mailgun signs its email as {$signer}, but {$domain}'s DMARC record asks for strict DKIM alignment"
                . " (adkim=s), which only an exact match passes. Send it with {$domain}'s own SMTP credentials (as"
                . ' another provider), or relax the alignment.';

            $check = $this->createCheck(SenderCheckLevel::WARNING, "Signed as {$signer}, not {$domain}", $detail);
        }
        elseif ($isSameOrganization)
        {
            $detail =
                "Mailgun DKIM-signs its email as {$signer}, which passes DMARC for {$domain}, as they're the same"
                . ' organization\'s domain, and DMARC accepts that unless its record asks for strict alignment.';

            $check = $this->createCheck(SenderCheckLevel::OK, "Covered by {$signer}'s signature", $detail);
        }
        else
        {
            $howTo = DomainDnsChecker::isFreeMailDomain($domain)
                ? "{$domain} can't be added to Mailgun, as only its owner controls its DNS. Use a domain you own."
                : "Add {$domain} (or a subdomain of it, e.g. mg.{$domain}) to Mailgun, and send its email through a"
                    . ' provider that logs in with that domain\'s SMTP credentials.';

            $outcome = $policy->isEnforced()
                ? " Its DMARC policy is p={$policy->value}, so receivers won't accept it."
                : ' That makes it likely to go to spam.';

            $detail =
                "Mailgun sends it, but signs everything this provider sends as {$signer}, which doesn't match"
                . " {$domain}, so it fails DMARC.{$outcome} {$howTo}";

            $level = $policy->isEnforced() ? SenderCheckLevel::ERROR : SenderCheckLevel::WARNING;
            $check = $this->createCheck($level, "Signed as {$signer}, not {$domain}", $detail);
        }

        return $check;
    }


    private function checkDomainState() : SenderCheck
    {
        $domain = $this->m_domain;
        $where = 'Sending → Domains in Mailgun';
        $state = $this->m_state;

        if ($state === MailgunDomainState::UNCHECKED)
        {
            $detail =
                "This provider has no api_key, so whether {$domain} is verified in Mailgun, and its webhooks, can't"
                . ' be checked. Set it to a key that can read domains and webhooks (an "Analyst" key, where Mailgun'
                . ' offers them).';

            $check = $this->createCheck(SenderCheckLevel::INFO, 'The domain can\'t be checked', $detail);
        }
        elseif ($state === MailgunDomainState::UNREADABLE)
        {
            $detail = "The api_key isn't allowed to read {$domain}. Give it read access to domains.";
            $check = $this->createCheck(SenderCheckLevel::WARNING, 'The domain can\'t be checked', $detail);
        }
        elseif ($state === MailgunDomainState::MISSING)
        {
            $detail =
                "smtp_username logs in as {$domain}, which isn't one of the account's domains in its region. Check"
                . ' the username, and the provider\'s region (us or eu).';

            $check = $this->createCheck(SenderCheckLevel::ERROR, "Mailgun has no domain {$domain}", $detail);
        }
        elseif ($state === MailgunDomainState::DISABLED)
        {
            $why = ($this->m_disabledReason === '') ? '' : " ({$this->m_disabledReason})";
            $detail = "Mailgun has disabled {$domain}{$why}, so it won't send its email. See {$where}.";
            $check = $this->createCheck(SenderCheckLevel::ERROR, "{$domain} is disabled", $detail);
        }
        elseif ($state === MailgunDomainState::SANDBOX)
        {
            $detail =
                'Mailgun only sends a sandbox domain\'s email to its authorized recipients (up to 5). Add a domain of'
                . ' your own, and use its SMTP credentials.';

            $check = $this->createCheck(SenderCheckLevel::WARNING, "{$domain} is a sandbox domain", $detail);
        }
        elseif ($state === MailgunDomainState::UNVERIFIED)
        {
            $records = (count($this->m_invalidRecords) > 0)
                ? ' Mailgun didn\'t find these DNS records: ' . implode('; ', $this->m_invalidRecords) . '.'
                : '';

            $detail =
                "Mailgun limits an unverified domain to 300 emails a day.{$records} Add them, then verify it again"
                . " ({$where}).";

            $check = $this->createCheck(SenderCheckLevel::ERROR, "{$domain} isn't verified", $detail);
        }
        else
        {
            $detail = "{$domain} is active in Mailgun, with its DNS records in place.";
            $check = $this->createCheck(SenderCheckLevel::OK, "{$domain} is verified", $detail);
        }

        return $check;
    }


    private function checkWebhooks() : SenderCheckCollection
    {
        $checks = [];
        $url = $this->m_webhookUrl;
        $here = ($url === '') ? 'this site\'s /webhooks/' . $this->m_providerName : $url;
        $missing = array_values(array_diff(self::RECORDED_WEBHOOKS, $this->m_webhookEvents));
        $missingEssential = array_values(array_intersect(self::ESSENTIAL_WEBHOOKS, $missing));
        $isPostingHere = (count($this->m_webhookEvents) > 0);

        $setUp =
            "add webhooks for {$this->m_domain} in Mailgun (Sending → Webhooks) that post to {$here}, and put the"
            . ' account\'s HTTP webhook signing key (Settings → API Security) in this provider\'s'
            . ' webhook_signing_key.';

        $isUntracked = ($isPostingHere === false || $this->m_canReadWebhooks === false);

        if ($this->m_isWebhookKeySet === false && $isUntracked)
        {
            $detail = "So deliveries, bounces and spam reports aren't recorded against each email. To record them, "
                . $setUp;
            $checks[] = $this->createCheck(SenderCheckLevel::INFO, 'Delivery events aren\'t tracked', $detail);
        }
        elseif ($this->m_isWebhookKeySet === false)
        {
            $detail =
                'Mailgun posts its events here, but this provider\'s webhook_signing_key isn\'t set, so they\'re all'
                . ' refused. Set it to the account\'s HTTP webhook signing key (Settings → API Security).';

            $checks[] = $this->createCheck(SenderCheckLevel::ERROR, 'Its events are all refused', $detail);
        }
        elseif ($this->m_state === MailgunDomainState::UNCHECKED || $this->m_canReadWebhooks === false)
        {
            $detail =
                'Giving the api_key read access to webhooks would let this check that Mailgun posts its events to'
                . " {$here}.";

            $checks[] = $this->createCheck(SenderCheckLevel::INFO, 'Can\'t check the webhooks', $detail);
        }
        elseif ($url === '')
        {
            $detail = 'PUBLIC_URL isn\'t set, so where Mailgun should be sending its events isn\'t known.';
            $checks[] = $this->createCheck(SenderCheckLevel::WARNING, 'Can\'t check the webhooks', $detail);
        }
        elseif (count($missingEssential) > 0)
        {
            $detail =
                "No webhook for " . implode(', ', $missingEssential) . " events posts to {$url}, so those aren't"
                . " recorded. To record them, {$setUp}";

            $checks[] = $this->createCheck(SenderCheckLevel::ERROR, 'Mailgun isn\'t sending events here', $detail);
        }
        else
        {
            $detail =
                "Mailgun posts its delivery and bounce events to {$url}. Whether they're signed with the key in"
                . ' webhook_signing_key can\'t be checked from here: if they\'re refused, the log says so.';

            $checks[] = $this->createCheck(SenderCheckLevel::OK, 'The webhooks are set up', $detail);
        }

        if ($this->m_canReadWebhooks && $isPostingHere && count($missing) > 0 && count($missingEssential) === 0)
        {
            $detail =
                'There\'s no webhook for ' . implode(', ', $missing) . ' events posting here, so those won\'t be'
                . ' recorded against each email.';

            $checks[] = $this->createCheck(SenderCheckLevel::INFO, 'Some events aren\'t sent', $detail);
        }

        return new SenderCheckCollection(...$checks);
    }


    /**
     * Describe each of a domain's sending DNS records Mailgun didn't find.
     *
     * @param mixed $records - the "sending_dns_records" from GET /v4/domains/{name}.
     *
     * @return list<string> - e.g. "TXT mx._domainkey.mg.example.org → k=rsa; p=MIGf...".
     */
    private static function describeInvalidRecords(mixed $records) : array
    {
        $invalid = [];

        foreach (is_array($records) ? $records : [] as $record)
        {
            if (is_array($record) && ($record['valid'] ?? '') !== 'valid')
            {
                $value = (string)($record['value'] ?? '');
                $shortValue = (strlen($value) > 40) ? substr($value, 0, 40) . '…' : $value;
                $type = strtoupper((string)($record['record_type'] ?? ''));
                $invalid[] = "{$type} {$record['name']} → {$shortValue}";
            }
        }

        return $invalid;
    }


    /**
     * Find the webhooks that post to an address.
     *
     * @param array $webhooks - the "webhooks" from GET /v3/domains/{domain}/webhooks, e.g.
     * {"delivered": {"urls": ["https://..."]}}.
     *
     * @param string $url - the address.
     *
     * @return list<string> - the webhooks' names, e.g. "delivered".
     */
    private static function findEventsPostingTo(array $webhooks, string $url) : array
    {
        $events = [];

        foreach ($webhooks as $event => $webhook)
        {
            $urls = (is_array($webhook) && is_array($webhook['urls'] ?? null)) ? $webhook['urls'] : [];

            foreach ($urls as $candidate)
            {
                if ($url !== '' && is_string($candidate) && rtrim($candidate, '/') === rtrim($url, '/'))
                {
                    $events[] = (string)$event;
                }
            }
        }

        return array_values(array_unique($events));
    }


    private function createCheck(SenderCheckLevel $level, string $title, string $detail) : SenderCheck
    {
        return new SenderCheck($level, $this->m_providerName, $title, $detail);
    }


    # Accessors
    public function getProviderName() : string { return $this->m_providerName; }
}
