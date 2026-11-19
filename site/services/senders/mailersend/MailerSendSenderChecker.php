<?php

/*
 * The sender checks for a MailerSend provider. MailerSend only sends from its account's verified domains, which it
 * DKIM-signs as themselves, so they pass DMARC.
 *
 * With an api_token, it reads the account's domains and their webhooks. The token needs "Domains" read access, and,
 * to check the webhooks, "Webhooks" full access, as MailerSend has no read-only access to them. Everything is read
 * with GET requests, when it's created.
 */

declare(strict_types = 1);


final readonly class MailerSendSenderChecker implements SenderCheckerInterface
{
    private const string API_URL = 'https://api.mailersend.com';

    // The most domains read, and the most whose webhooks are read (one request each).
    private const int MAX_DOMAINS = 100;
    private const int MAX_WEBHOOK_DOMAINS = 10;

    // The webhook events that are recorded, and the ones without which deliveries and bounces aren't.
    private const array RECORDED_EVENTS = [
        'activity.sent', 'activity.delivered', 'activity.soft_bounced', 'activity.hard_bounced',
        'activity.spam_complaint', 'activity.unsubscribed', 'activity.opened', 'activity.clicked',
    ];

    private const array ESSENTIAL_EVENTS = ['activity.delivered', 'activity.soft_bounced', 'activity.hard_bounced'];

    private string $m_providerName;
    private bool $m_hasApi;
    private IdentityChecks $m_identities;
    private SecretWebhookChecks $m_webhookChecks;


    /**
     * @param string $providerName - the provider's name in settings.yaml.
     *
     * @param bool $hasApi - whether the provider has an api_token, so the rest was read.
     *
     * @param AuthenticatedDomainCollection $domains - the account's domains, valid if they're verified.
     *
     * @param bool $canReadWebhooks - whether the token could list the domains' webhooks.
     *
     * @param ConfiguredWebhookCollection $webhooks - the domains' enabled webhooks.
     *
     * @param string $webhookSecret - the provider's webhook_secret, or an empty string if it isn't set.
     *
     * @param string $webhookUrl - where MailerSend should send its events, or an empty string if that isn't known.
     */
    public function __construct(
        string $providerName,
        bool $hasApi,
        AuthenticatedDomainCollection $domains,
        bool $canReadWebhooks,
        ConfiguredWebhookCollection $webhooks,
        string $webhookSecret,
        string $webhookUrl
    )
    {
        $this->m_providerName = $providerName;
        $this->m_hasApi = $hasApi;
        $addresses = new VerifiedAddressCollection();
        $profile = self::createProfile();
        $this->m_identities = new IdentityChecks($providerName, $profile, $hasApi, $domains, true, $addresses);

        $this->m_webhookChecks = new SecretWebhookChecks(
            $providerName,
            'MailerSend',
            'Integrations → Webhooks',
            WebhookAuthentication::SIGNATURE,
            $hasApi && $canReadWebhooks,
            true,
            $webhooks,
            $webhookSecret,
            $webhookUrl,
            self::RECORDED_EVENTS,
            self::ESSENTIAL_EVENTS
        );
    }


    /**
     * Read everything the checks need from MailerSend's API.
     *
     * @param string $providerName - the provider's name in settings.yaml.
     *
     * @param JsonApiClient|null $api - MailerSend's API, with the provider's api_token (see createApiClient()), or
     * null if it has none.
     *
     * @param string $webhookSecret - the provider's webhook_secret, or an empty string if it isn't set.
     *
     * @param string $webhookUrl - where MailerSend should send its events, or an empty string if that isn't known.
     *
     * @return MailerSendSenderChecker
     *
     * @throws ProviderLookupException - if MailerSend couldn't be reached, or rejected the token.
     */
    public static function fetch(
        string $providerName,
        ?JsonApiClient $api,
        string $webhookSecret,
        string $webhookUrl
    ) : MailerSendSenderChecker
    {
        $domains = [];
        $webhooks = [];
        $canReadWebhooks = true;
        $path = '/v1/domains?limit=' . self::MAX_DOMAINS;
        $entries = ($api === null) ? [] : ($api->fetchJson($path)['data'] ?? []);
        $webhookDomainsRead = 0;

        foreach (is_array($entries) ? $entries : [] as $entry)
        {
            if (is_array($entry) && is_string($entry['name'] ?? null))
            {
                $domains[] = self::createDomainFromApi($entry);

                if ($canReadWebhooks && $webhookDomainsRead < self::MAX_WEBHOOK_DOMAINS)
                {
                    try
                    {
                        array_push($webhooks, ...self::fetchWebhooks($api, (string)($entry['id'] ?? ''))->toArray());
                    }
                    catch (ProviderPermissionException)
                    {
                        $canReadWebhooks = false;
                    }

                    $webhookDomainsRead++;
                }
            }
        }

        return new MailerSendSenderChecker(
            $providerName,
            $api !== null,
            new AuthenticatedDomainCollection(...$domains),
            $canReadWebhooks,
            new ConfiguredWebhookCollection(...$webhooks),
            $webhookSecret,
            $webhookUrl
        );
    }


    /**
     * Create a client for MailerSend's API.
     *
     * @param string $apiToken - the API token.
     *
     * @return JsonApiClient
     */
    public static function createApiClient(string $apiToken) : JsonApiClient
    {
        return JsonApiClient::create('MailerSend', self::API_URL, ['Authorization' => "Bearer {$apiToken}"]);
    }


    public function checkAccount() : SenderCheckCollection
    {
        $checks = [];

        if ($this->m_hasApi === false)
        {
            $detail =
                'This provider has no api_token, so whether each sender\'s domain is verified in MailerSend, and its'
                . ' webhook, can\'t be checked. Set it to a token with read access to domains (and full access to'
                . ' webhooks, to check them too).';

            $checks[] = $this->createCheck(SenderCheckLevel::INFO, 'The senders can\'t be checked', $detail);
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
     * Describe how MailerSend verifies senders, for IdentityChecks: by domain only.
     *
     * @return ProviderProfile
     */
    private static function createProfile() : ProviderProfile
    {
        return new ProviderProfile('MailerSend', 'Domains', UnverifiedSenderHandling::REFUSED, '', '');
    }


    /**
     * Create an authenticated domain from an entry in GET /v1/domains, valid if it's verified with its DKIM.
     *
     * @param array $entry - the entry, decoded from JSON.
     *
     * @return AuthenticatedDomain
     */
    private static function createDomainFromApi(array $entry) : AuthenticatedDomain
    {
        $isVerified = (($entry['is_verified'] ?? false) === true);
        $isDkimFound = (($entry['dkim'] ?? false) === true);
        $isSpfFound = (($entry['spf'] ?? false) === true);
        $missing = [];

        if ($isDkimFound === false)
        {
            $missing[] = 'the DKIM CNAME records MailerSend shows for it';
        }

        if ($isSpfFound === false)
        {
            $missing[] = 'its SPF record';
        }

        return new AuthenticatedDomain((string)$entry['name'], $isVerified && $isDkimFound, $missing, 0);
    }


    /**
     * Read a domain's enabled webhooks.
     *
     * @param JsonApiClient $api - the API.
     *
     * @param string $domainId - the domain's ID.
     *
     * @return ConfiguredWebhookCollection - the ones that are switched on.
     *
     * @throws ProviderPermissionException - if the token isn't allowed to read them.
     *
     * @throws ProviderLookupException - if MailerSend couldn't be reached, or rejected the token.
     */
    private static function fetchWebhooks(JsonApiClient $api, string $domainId) : ConfiguredWebhookCollection
    {
        $webhooks = [];
        $entries = $api->fetchJson('/v1/webhooks?domain_id=' . rawurlencode($domainId))['data'] ?? [];

        foreach (is_array($entries) ? $entries : [] as $entry)
        {
            if (is_array($entry) && is_string($entry['url'] ?? null) && ($entry['enabled'] ?? true) === true)
            {
                $events = is_array($entry['events'] ?? null) ? array_map('strval', $entry['events']) : [];
                $webhooks[] = new ConfiguredWebhook($entry['url'], (string)($entry['secret'] ?? ''), $events);
            }
        }

        return new ConfiguredWebhookCollection(...$webhooks);
    }


    private function createCheck(SenderCheckLevel $level, string $title, string $detail) : SenderCheck
    {
        return new SenderCheck($level, $this->m_providerName, $title, $detail);
    }


    # Accessors
    public function getProviderName() : string { return $this->m_providerName; }
}
