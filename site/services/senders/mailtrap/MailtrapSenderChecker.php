<?php

/*
 * The sender checks for a Mailtrap provider. Mailtrap only sends from its account's sending domains, once their DNS
 * records are in place, and only to anyone other than the account's owner once Mailtrap has reviewed the domain
 * ("compliant").
 *
 * With an api_token (a viewer is enough), it reads the account's sending domains and webhooks. Mailtrap's API
 * doesn't show a webhook's signing secret after it's created, so that can't be checked. Everything is read with GET
 * requests, when it's created.
 */

declare(strict_types = 1);


final readonly class MailtrapSenderChecker implements SenderCheckerInterface
{
    private const string API_URL = 'https://mailtrap.io';

    // The webhook events that are recorded, and the ones without which deliveries and bounces aren't.
    private const array RECORDED_EVENTS = [
        'delivery', 'soft_bounce', 'bounce', 'suspension', 'reject', 'spam_complaint', 'unsubscribe', 'open', 'click',
    ];

    private const array ESSENTIAL_EVENTS = ['delivery', 'soft_bounce', 'bounce'];

    private string $m_providerName;
    private bool $m_hasApi;
    private array $m_complianceStatuses;
    private IdentityChecks $m_identities;
    private SecretWebhookChecks $m_webhookChecks;


    /**
     * @param string $providerName - the provider's name in settings.yaml.
     *
     * @param bool $hasApi - whether the provider has an api_token, so the rest was read.
     *
     * @param AuthenticatedDomainCollection $domains - the sending domains, valid if their DNS is verified.
     *
     * @param array<string, string> $complianceStatuses - each domain's compliance_status, by domain.
     *
     * @param bool $canReadWebhooks - whether the token could list the webhooks.
     *
     * @param ConfiguredWebhookCollection $webhooks - the active webhooks.
     *
     * @param string $webhookSecret - the provider's webhook_secret, or an empty string if it isn't set.
     *
     * @param string $webhookUrl - where Mailtrap should send its events, or an empty string if that isn't known.
     */
    public function __construct(
        string $providerName,
        bool $hasApi,
        AuthenticatedDomainCollection $domains,
        array $complianceStatuses,
        bool $canReadWebhooks,
        ConfiguredWebhookCollection $webhooks,
        string $webhookSecret,
        string $webhookUrl
    )
    {
        $this->m_providerName = $providerName;
        $this->m_hasApi = $hasApi;
        $this->m_complianceStatuses = $complianceStatuses;
        $addresses = new VerifiedAddressCollection();
        $profile = new ProviderProfile('Mailtrap', 'Sending Domains', UnverifiedSenderHandling::REFUSED, '', '');
        $this->m_identities = new IdentityChecks($providerName, $profile, $hasApi, $domains, true, $addresses);

        $this->m_webhookChecks = new SecretWebhookChecks(
            $providerName,
            'Mailtrap',
            'Settings → Webhooks',
            WebhookAuthentication::SIGNATURE,
            $hasApi && $canReadWebhooks,
            false,
            $webhooks,
            $webhookSecret,
            $webhookUrl,
            self::RECORDED_EVENTS,
            self::ESSENTIAL_EVENTS
        );
    }


    /**
     * Read everything the checks need from Mailtrap's API. Its sending domains are read from /api/domains, or, if
     * that isn't found, from the account's /sending_domains, the path Mailtrap's API used before.
     *
     * @param string $providerName - the provider's name in settings.yaml.
     *
     * @param JsonApiClient|null $api - Mailtrap's API, with the provider's api_token (see createApiClient()), or
     * null if it has none.
     *
     * @param string $webhookSecret - the provider's webhook_secret, or an empty string if it isn't set.
     *
     * @param string $webhookUrl - where Mailtrap should send its events, or an empty string if that isn't known.
     *
     * @return MailtrapSenderChecker
     *
     * @throws ProviderLookupException - if Mailtrap couldn't be reached, or rejected the token.
     */
    public static function fetch(
        string $providerName,
        ?JsonApiClient $api,
        string $webhookSecret,
        string $webhookUrl
    ) : MailtrapSenderChecker
    {
        $domains = [];
        $statuses = [];
        $webhooks = [];
        $canReadWebhooks = false;

        if ($api !== null)
        {
            foreach (self::fetchDomainEntries($api) as $entry)
            {
                $domain = self::createDomainFromApi($entry);
                $domains[] = $domain;
                $statuses[$domain->getDomain()] = (string)($entry['compliance_status'] ?? '');
            }

            try
            {
                $entries = self::extractList($api->fetchJson('/api/webhooks'));
                $canReadWebhooks = true;
            }
            catch (ProviderPermissionException | ProviderNotFoundException)
            {
                $entries = [];
            }

            foreach ($entries as $entry)
            {
                if (is_string($entry['url'] ?? null) && ($entry['active'] ?? true) === true)
                {
                    $types = $entry['event_types'] ?? [];
                    $events = is_array($types) ? array_map('strval', $types) : [];
                    $webhooks[] = new ConfiguredWebhook($entry['url'], '', $events);
                }
            }
        }

        return new MailtrapSenderChecker(
            $providerName,
            $api !== null,
            new AuthenticatedDomainCollection(...$domains),
            $statuses,
            $canReadWebhooks,
            new ConfiguredWebhookCollection(...$webhooks),
            $webhookSecret,
            $webhookUrl
        );
    }


    /**
     * Create a client for Mailtrap's API.
     *
     * @param string $apiToken - the API token.
     *
     * @return JsonApiClient
     */
    public static function createApiClient(string $apiToken) : JsonApiClient
    {
        return JsonApiClient::create('Mailtrap', self::API_URL, ['Api-Token' => $apiToken]);
    }


    public function checkAccount() : SenderCheckCollection
    {
        $checks = [];

        if ($this->m_hasApi === false)
        {
            $detail =
                'This provider has no api_token, so whether each sender\'s domain is verified in Mailtrap, and its'
                . ' webhook, can\'t be checked. Set it to a token with (at least viewer) access to the account.';

            $checks[] = $this->createCheck(SenderCheckLevel::INFO, 'The senders can\'t be checked', $detail);
        }

        foreach ($this->m_complianceStatuses as $domain => $status)
        {
            if ($status !== 'compliant' && $status !== '')
            {
                $detail =
                    "Its compliance status is \"{$status}\": until Mailtrap has reviewed it (and it's \"compliant\"),"
                    . ' it only sends to the account\'s owner. See Sending Domains → the domain in Mailtrap.';

                $isRefused = in_array($status, ['suspended', 'non_compliant'], true);
                $level = $isRefused ? SenderCheckLevel::ERROR : SenderCheckLevel::WARNING;
                $checks[] = $this->createCheck($level, "{$domain} can't send to anyone yet", $detail);
            }
        }

        foreach ($this->m_webhookChecks->check() as $check)
        {
            $checks[] = $check;
        }

        return new SenderCheckCollection(...$checks);
    }


    public function checkDomain(DomainDns $dns) : SenderCheckCollection
    {
        return $this->m_identities->checkDomain($dns);
    }


    public function checkAddress(EmailAddress $address, DomainDns $dns) : SenderCheckCollection
    {
        return $this->m_identities->checkAddress($address, $dns);
    }


    public function checkAnySender() : SenderCheckCollection
    {
        return $this->m_identities->checkAnySender();
    }


    /**
     * Read the sending domains, from the current path or the older one.
     *
     * @param JsonApiClient $api - the API.
     *
     * @return list<array> - each domain, decoded from JSON.
     *
     * @throws ProviderLookupException - if Mailtrap couldn't be reached, or rejected the token.
     */
    private static function fetchDomainEntries(JsonApiClient $api) : array
    {
        try
        {
            $entries = self::extractList($api->fetchJson('/api/domains'));
        }
        catch (ProviderNotFoundException)
        {
            $entries = [];

            foreach (self::extractList($api->fetchJson('/api/accounts')) as $account)
            {
                $path = '/api/accounts/' . rawurlencode((string)($account['id'] ?? '')) . '/sending_domains';
                $entries = [...$entries, ...self::extractList($api->fetchJson($path))];
            }
        }

        $domains = [];

        foreach ($entries as $entry)
        {
            if (is_string($entry['domain_name'] ?? null))
            {
                $domains[] = $entry;
            }
        }

        return $domains;
    }


    /**
     * Get the entries of one of Mailtrap's lists, which are either the response itself, or its "data".
     *
     * @param array $response - the response, decoded from JSON.
     *
     * @return list<array>
     */
    private static function extractList(array $response) : array
    {
        $list = array_is_list($response) ? $response : ($response['data'] ?? []);
        $entries = [];

        foreach (is_array($list) ? $list : [] as $entry)
        {
            if (is_array($entry))
            {
                $entries[] = $entry;
            }
        }

        return $entries;
    }


    /**
     * Create an authenticated domain from one of Mailtrap's sending domains, valid if its DNS is verified, with
     * each of its records that didn't pass.
     *
     * @param array $entry - the domain, decoded from JSON.
     *
     * @return AuthenticatedDomain
     */
    private static function createDomainFromApi(array $entry) : AuthenticatedDomain
    {
        $missing = [];
        $records = is_array($entry['dns_records'] ?? null) ? $entry['dns_records'] : [];

        foreach ($records as $record)
        {
            // Inbound MX is only for receiving email, which sending doesn't need.
            $isFailing = (is_array($record) && ($record['status'] ?? 'pass') !== 'pass');

            if ($isFailing && ($record['key'] ?? '') !== 'inbound_mx')
            {
                $value = (string)($record['value'] ?? '');
                $shortValue = (strlen($value) > 40) ? substr($value, 0, 40) . '…' : $value;
                $type = strtoupper((string)($record['type'] ?? ''));
                $missing[] = "{$type} " . ($record['domain'] ?? '') . " → {$shortValue}";
            }
        }

        $verifiedAt = strtotime((string)($entry['dns_verified_at'] ?? ''));
        $isVerified = (($entry['dns_verified'] ?? false) === true);
        $lastCheckedAt = is_int($verifiedAt) ? $verifiedAt : 0;
        return new AuthenticatedDomain($entry['domain_name'], $isVerified, $missing, $lastCheckedAt);
    }


    private function createCheck(SenderCheckLevel $level, string $title, string $detail) : SenderCheck
    {
        return new SenderCheck($level, $this->m_providerName, $title, $detail);
    }


    # Accessors
    public function getProviderName() : string { return $this->m_providerName; }
}
