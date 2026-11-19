<?php

/*
 * The sender checks for a Brevo provider. Brevo only sends from a sender it has verified: a domain authenticated
 * with its DKIM record, or one address verified on its own (which Brevo then signs as its own domain, so it fails
 * DMARC).
 *
 * With an api_key, it reads Brevo's authenticated domains (and the records it hasn't found for those that aren't),
 * its senders, whether the account's SMTP relay is switched on, and its transactional webhooks. Brevo's API keys
 * can't be limited to reading, so the key the checks use can also change the account: Brevo's OAuth tokens can be,
 * but expire within the hour, so aren't usable here. Everything is read with GET requests, when it's created.
 */

declare(strict_types = 1);


final readonly class BrevoSenderChecker implements SenderCheckerInterface
{
    private const string API_URL = 'https://api.brevo.com';

    // How many domains to read the missing records of, at most (one request each).
    private const int MAX_DOMAIN_DETAILS = 10;

    // The webhook events that are recorded (as Brevo names them when subscribing), and the essential ones.
    private const array RECORDED_EVENTS = [
        'delivered', 'hardBounce', 'softBounce', 'deferred', 'blocked', 'invalid', 'error', 'spam', 'opened',
        'click', 'unsubscribed',
    ];

    private const array ESSENTIAL_EVENTS = ['delivered', 'hardBounce', 'softBounce'];

    private string $m_providerName;
    private bool $m_hasApi;
    private bool $m_isRelayEnabled;
    private IdentityChecks $m_identities;
    private SecretWebhookChecks $m_webhookChecks;


    /**
     * @param string $providerName - the provider's name in settings.yaml.
     *
     * @param bool $hasApi - whether the provider has an api_key, so the rest was read.
     *
     * @param bool $isRelayEnabled - whether the account's SMTP relay is switched on.
     *
     * @param AuthenticatedDomainCollection $domains - the account's domains, valid if they're authenticated.
     *
     * @param VerifiedAddressCollection $senders - its senders, verified if they're active.
     *
     * @param ConfiguredWebhookCollection $webhooks - its transactional webhooks.
     *
     * @param string $webhookSecret - the provider's webhook_secret, or an empty string if it isn't set.
     *
     * @param string $webhookUrl - where Brevo should send its events, or an empty string if that isn't known.
     */
    public function __construct(
        string $providerName,
        bool $hasApi,
        bool $isRelayEnabled,
        AuthenticatedDomainCollection $domains,
        VerifiedAddressCollection $senders,
        ConfiguredWebhookCollection $webhooks,
        string $webhookSecret,
        string $webhookUrl
    )
    {
        $this->m_providerName = $providerName;
        $this->m_hasApi = $hasApi;
        $this->m_isRelayEnabled = $isRelayEnabled;
        $profile = self::createProfile();
        $this->m_identities = new IdentityChecks($providerName, $profile, $hasApi, $domains, $hasApi, $senders);

        $this->m_webhookChecks = new SecretWebhookChecks(
            $providerName,
            'Brevo',
            'Transactional → Settings → Webhook',
            WebhookAuthentication::PASSWORD,
            $hasApi,
            true,
            $webhooks,
            $webhookSecret,
            $webhookUrl,
            self::RECORDED_EVENTS,
            self::ESSENTIAL_EVENTS
        );
    }


    /**
     * Read everything the checks need from Brevo's API.
     *
     * @param string $providerName - the provider's name in settings.yaml.
     *
     * @param JsonApiClient|null $api - Brevo's API, with the provider's api_key (see createApiClient()), or null if
     * it has none.
     *
     * @param string $webhookSecret - the provider's webhook_secret, or an empty string if it isn't set.
     *
     * @param string $webhookUrl - where Brevo should send its events, or an empty string if that isn't known.
     *
     * @return BrevoSenderChecker
     *
     * @throws ProviderLookupException - if Brevo couldn't be reached, or rejected the API key.
     */
    public static function fetch(
        string $providerName,
        ?JsonApiClient $api,
        string $webhookSecret,
        string $webhookUrl
    ) : BrevoSenderChecker
    {
        $isRelayEnabled = true;
        $senders = [];
        $webhooks = [];

        if ($api !== null)
        {
            $relay = $api->fetchJson('/v3/account')['relay'] ?? [];
            $isRelayEnabled = (is_array($relay) && ($relay['enabled'] ?? true) === true);
            $domains = self::fetchDomains($api);

            $senderEntries = $api->fetchJson('/v3/senders')['senders'] ?? [];
            $webhookEntries = $api->fetchJson('/v3/webhooks?type=transactional')['webhooks'] ?? [];

            foreach (is_array($senderEntries) ? $senderEntries : [] as $sender)
            {
                if (is_array($sender) && is_string($sender['email'] ?? null))
                {
                    $senders[] = new VerifiedAddress($sender['email'], ($sender['active'] ?? false) === true);
                }
            }

            foreach (is_array($webhookEntries) ? $webhookEntries : [] as $webhook)
            {
                if (is_array($webhook) && is_string($webhook['url'] ?? null))
                {
                    $webhooks[] = self::createWebhookFromApi($webhook);
                }
            }
        }
        else
        {
            $domains = new AuthenticatedDomainCollection();
        }

        return new BrevoSenderChecker(
            $providerName,
            $api !== null,
            $isRelayEnabled,
            $domains,
            new VerifiedAddressCollection(...$senders),
            new ConfiguredWebhookCollection(...$webhooks),
            $webhookSecret,
            $webhookUrl
        );
    }


    /**
     * Create a client for Brevo's API.
     *
     * @param string $apiKey - the API key.
     *
     * @return JsonApiClient
     */
    public static function createApiClient(string $apiKey) : JsonApiClient
    {
        return JsonApiClient::create('Brevo', self::API_URL, ['api-key' => $apiKey]);
    }


    public function checkAccount() : SenderCheckCollection
    {
        $checks = [];

        if ($this->m_hasApi === false)
        {
            $detail =
                'This provider has no api_key, so whether each sender is verified in Brevo, and its webhook, can\'t'
                . ' be checked. Set it to one of the account\'s API keys (SMTP & API → API Keys), which Brevo can\'t'
                . ' limit to reading.';

            $checks[] = $this->createCheck(SenderCheckLevel::INFO, 'The senders can\'t be checked', $detail);
        }
        elseif ($this->m_isRelayEnabled === false)
        {
            $detail = 'Brevo refuses every email sent through it. Ask Brevo to switch on the account\'s SMTP relay.';
            $checks[] = $this->createCheck(SenderCheckLevel::ERROR, 'The SMTP relay is switched off', $detail);
        }
        else
        {
            $checks[] = $this->createCheck(SenderCheckLevel::OK, 'The account\'s SMTP relay is switched on', '');
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
     * Describe how Brevo verifies senders, for IdentityChecks.
     *
     * @return ProviderProfile
     */
    private static function createProfile() : ProviderProfile
    {
        return new ProviderProfile(
            'Brevo',
            'Senders, Domains & Dedicated IPs → Domains',
            UnverifiedSenderHandling::REFUSED,
            '',
            'Brevo\'s own domain'
        );
    }


    /**
     * Read the account's domains, and the records Brevo hasn't found for those that aren't authenticated.
     *
     * @param JsonApiClient $api - the API.
     *
     * @return AuthenticatedDomainCollection
     *
     * @throws ProviderLookupException - if Brevo couldn't be reached, or rejected the API key.
     */
    private static function fetchDomains(JsonApiClient $api) : AuthenticatedDomainCollection
    {
        $domains = [];
        $detailsRead = 0;

        $entries = $api->fetchJson('/v3/senders/domains')['domains'] ?? [];

        foreach (is_array($entries) ? $entries : [] as $entry)
        {
            if (is_array($entry) && is_string($entry['domain_name'] ?? null))
            {
                $name = $entry['domain_name'];
                $isAuthenticated = (($entry['authenticated'] ?? false) === true);
                $missing = [];

                if ($isAuthenticated === false && $detailsRead < self::MAX_DOMAIN_DETAILS)
                {
                    $missing = self::fetchMissingRecords($api, $name);
                    $detailsRead++;
                }

                $domains[] = new AuthenticatedDomain($name, $isAuthenticated, $missing, 0);
            }
        }

        return new AuthenticatedDomainCollection(...$domains);
    }


    /**
     * Read the DNS records Brevo hasn't found for a domain.
     *
     * @param JsonApiClient $api - the API.
     *
     * @param string $domain - the domain.
     *
     * @return list<string> - each record, described, e.g. "TXT mail._domainkey.example.org → k=rsa; p=MIGf…".
     *
     * @throws ProviderLookupException - if Brevo couldn't be reached, or rejected the API key.
     */
    private static function fetchMissingRecords(JsonApiClient $api, string $domain) : array
    {
        $missing = [];

        try
        {
            $records = $api->fetchJson('/v3/senders/domains/' . rawurlencode($domain))['dns_records'] ?? [];
        }
        catch (ProviderNotFoundException)
        {
            $records = [];
        }

        foreach (is_array($records) ? $records : [] as $record)
        {
            if (is_array($record) && ($record['status'] ?? true) === false)
            {
                $host = (string)($record['host_name'] ?? '');
                $name = ($host === '' || $host === '@') ? $domain : "{$host}.{$domain}";
                $value = (string)($record['value'] ?? '');
                $shortValue = (strlen($value) > 40) ? substr($value, 0, 40) . '…' : $value;
                $missing[] = strtoupper((string)($record['type'] ?? '')) . " {$name} → {$shortValue}";
            }
        }

        return $missing;
    }


    /**
     * Create a configured webhook from an entry in GET /v3/webhooks. Its secret is its bearer token, or the
     * password in its address.
     *
     * @param array $entry - the entry, decoded from JSON.
     *
     * @return ConfiguredWebhook
     */
    private static function createWebhookFromApi(array $entry) : ConfiguredWebhook
    {
        $url = (string)$entry['url'];
        $auth = is_array($entry['auth'] ?? null) ? $entry['auth'] : [];
        $token = is_string($auth['token'] ?? null) ? $auth['token'] : '';
        $secret = ($token !== '') ? $token : WebhookBasicAuth::getPasswordInUrl($url);
        $events = is_array($entry['events'] ?? null) ? array_map('strval', $entry['events']) : [];
        return new ConfiguredWebhook($url, $secret, $events);
    }


    private function createCheck(SenderCheckLevel $level, string $title, string $detail) : SenderCheck
    {
        return new SenderCheck($level, $this->m_providerName, $title, $detail);
    }


    # Accessors
    public function getProviderName() : string { return $this->m_providerName; }
}
