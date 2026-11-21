<?php

/*
 * The sender checks for an SMTP2GO provider. With an api_key, it reads the account's sender domains (valid once both
 * their DKIM and return-path records are verified) and its single sender emails. SMTP2GO's API needs POST even to
 * read, so these are read through JsonApiClient::fetchJsonUsingPost(), which can only reach view-only endpoints.
 */

declare(strict_types = 1);


final readonly class Smtp2GoSenderChecker implements SenderCheckerInterface
{
    private const string API_URL = 'https://api.smtp2go.com/v3';

    private string $m_providerName;
    private bool $m_hasApi;
    private bool $m_canReadDomains;
    private bool $m_canReadAddresses;
    private bool $m_isMissingSendPermission;
    private IdentityChecks $m_identities;
    private SecretWebhookChecks $m_webhookChecks;


    /**
     * @param string $providerName - the provider's name in settings.yaml.
     *
     * @param bool $hasApi - whether the provider has an api_key.
     *
     * @param bool $canReadDomains - whether the key could list the sender domains.
     *
     * @param AuthenticatedDomainCollection $domains - the sender domains.
     *
     * @param bool $canReadAddresses - whether the key could list the single sender emails.
     *
     * @param VerifiedAddressCollection $addresses - the single sender emails.
     *
     * @param bool $isMissingSendPermission - whether the provider sends with its API key, but the key can't send.
     *
     * @param bool $canReadWebhooks - whether the key could list the webhooks.
     *
     * @param ConfiguredWebhookCollection $webhooks - the webhooks that are switched on.
     *
     * @param string $webhookSecret - the provider's webhook_secret, or an empty string if it isn't set.
     *
     * @param string $webhookUrl - where SMTP2GO should send its events, or an empty string if that isn't known.
     */
    public function __construct(
        string $providerName,
        bool $hasApi,
        bool $canReadDomains,
        AuthenticatedDomainCollection $domains,
        bool $canReadAddresses,
        VerifiedAddressCollection $addresses,
        bool $isMissingSendPermission,
        bool $canReadWebhooks,
        ConfiguredWebhookCollection $webhooks,
        string $webhookSecret,
        string $webhookUrl
    )
    {
        // SMTP2GO doesn't document whether it shows a webhook's password, so it isn't relied on to check it.
        $this->m_webhookChecks = new SecretWebhookChecks(
            $providerName,
            'SMTP2GO',
            'Settings → Webhooks',
            WebhookAuthentication::PASSWORD,
            $canReadWebhooks,
            false,
            $webhooks,
            $webhookSecret,
            $webhookUrl,
            Smtp2GoWebhook::RECORDED_EVENTS,
            Smtp2GoWebhook::ESSENTIAL_EVENTS
        );

        $this->m_isMissingSendPermission = $isMissingSendPermission;
        $this->m_providerName = $providerName;
        $this->m_hasApi = $hasApi;
        $this->m_canReadDomains = $canReadDomains;
        $this->m_canReadAddresses = $canReadAddresses;
        $handling = UnverifiedSenderHandling::REFUSED;
        $profile = new ProviderProfile('SMTP2GO', 'Sending → Verified Senders', $handling, '', 'its own domain');

        $this->m_identities = new IdentityChecks(
            $providerName,
            $profile,
            $canReadDomains,
            $domains,
            $canReadAddresses,
            $addresses
        );
    }


    /**
     * Read the sender domains and single sender emails from SMTP2GO's API.
     *
     * @param string $providerName - the provider's name in settings.yaml.
     *
     * @param JsonApiClient|null $api - SMTP2GO's API (see createApiClient()), or null if it has no api_key.
     *
     * @param bool $isSendingWithApi - whether the provider has no SMTP login, so its key has to be able to send.
     *
     * @param string $webhookSecret - the provider's webhook_secret, or an empty string if it isn't set.
     *
     * @param string $webhookUrl - where SMTP2GO should send its events, or an empty string if that isn't known.
     *
     * @return Smtp2GoSenderChecker
     *
     * @throws ProviderLookupException - if SMTP2GO couldn't be reached, or rejected the key.
     */
    public static function fetch(
        string $providerName,
        ?JsonApiClient $api,
        bool $isSendingWithApi,
        string $webhookSecret,
        string $webhookUrl
    ) : Smtp2GoSenderChecker
    {
        $domains = [];
        $addresses = [];
        $webhooks = [];
        $canReadDomains = false;
        $canReadAddresses = false;
        $canReadWebhooks = false;
        $isMissingSendPermission = false;

        if ($api !== null && $isSendingWithApi)
        {
            try
            {
                $permissions = $api->fetchJsonUsingPost(ReadOnlyPostEndpoint::SMTP2GO_KEY_PERMISSIONS)['data'] ?? [];
                $granted = is_array($permissions) ? $permissions : [];
                $isMissingSendPermission = (count(array_intersect(['/email/mime', '/email/*', '*'], $granted)) === 0);
            }
            catch (ProviderPermissionException)
            {
                // What it's allowed to do isn't known, so it isn't reported as missing.
                $isMissingSendPermission = false;
            }
        }

        if ($api !== null)
        {
            try
            {
                $response = $api->fetchJsonUsingPost(ReadOnlyPostEndpoint::SMTP2GO_SENDER_DOMAINS);
                $canReadDomains = true;

                foreach (self::extractList($response, 'domains') as $entry)
                {
                    if (is_array($entry['domain'] ?? null) && is_string($entry['domain']['fulldomain'] ?? null))
                    {
                        $domains[] = self::createDomainFromApi($entry['domain']);
                    }
                }
            }
            catch (ProviderPermissionException)
            {
                $domains = [];
            }

            try
            {
                $response = $api->fetchJsonUsingPost(ReadOnlyPostEndpoint::SMTP2GO_SINGLE_SENDERS);
                $canReadAddresses = true;

                foreach (self::extractList($response, 'senders') as $entry)
                {
                    if (is_string($entry['email_address'] ?? null))
                    {
                        $isVerified = (($entry['verified'] ?? false) === true);
                        $addresses[] = new VerifiedAddress($entry['email_address'], $isVerified);
                    }
                }
            }
            catch (ProviderPermissionException)
            {
                $addresses = [];
            }

            try
            {
                $response = $api->fetchJsonUsingPost(ReadOnlyPostEndpoint::SMTP2GO_WEBHOOKS);
                $canReadWebhooks = true;

                foreach (Smtp2GoWebhook::extractConfigured($response) as $entry)
                {
                    if (($entry['disabled'] ?? false) !== true)
                    {
                        $events = is_array($entry['events'] ?? null) ? array_map('strval', $entry['events']) : [];
                        $password = WebhookBasicAuth::getPasswordInUrl((string)$entry['url']);
                        $webhooks[] = new ConfiguredWebhook((string)$entry['url'], $password, $events);
                    }
                }
            }
            catch (ProviderPermissionException | ProviderNotFoundException)
            {
                $webhooks = [];
            }
        }

        return new Smtp2GoSenderChecker(
            $providerName,
            $api !== null,
            $canReadDomains,
            new AuthenticatedDomainCollection(...$domains),
            $canReadAddresses,
            new VerifiedAddressCollection(...$addresses),
            $isMissingSendPermission,
            $canReadWebhooks,
            new ConfiguredWebhookCollection(...$webhooks),
            $webhookSecret,
            $webhookUrl
        );
    }


    /**
     * Create a client for SMTP2GO's API.
     *
     * @param string $apiKey - the API key.
     *
     * @return JsonApiClient
     */
    public static function createApiClient(string $apiKey) : JsonApiClient
    {
        return JsonApiClient::create('SMTP2GO', self::API_URL, ['X-Smtp2go-Api-Key' => $apiKey]);
    }


    public function checkAccount() : SenderCheckCollection
    {
        $checks = [];

        if ($this->m_isMissingSendPermission)
        {
            $detail =
                'This provider has no SMTP login, so it sends with its API key, which isn\'t allowed to use'
                . ' /email/mime. Add that permission (Sending → API Keys → Permissions in SMTP2GO), or add an SMTP'
                . ' login.';

            $checks[] = $this->createCheck(SenderCheckLevel::ERROR, 'The API key can\'t send email', $detail);
        }

        if ($this->m_hasApi === false)
        {
            $detail =
                'This provider has no api_key, so whether each sender is verified in SMTP2GO can\'t be checked. Set it'
                . ' to a key allowed to view sender domains and single sender emails.';

            $checks[] = $this->createCheck(SenderCheckLevel::INFO, 'The senders can\'t be checked', $detail);
        }
        elseif ($this->m_canReadDomains === false || $this->m_canReadAddresses === false)
        {
            $detail =
                'Allow the API key to use /domain/view and /single_sender_emails/view (Sending → API Keys →'
                . ' Permissions in SMTP2GO), so every sender can be checked.';

            $title = 'The API key can\'t read all the senders';
            $checks[] = $this->createCheck(SenderCheckLevel::WARNING, $title, $detail);
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
     * Get a list from a response's "data".
     *
     * @param array $response - the response, decoded from JSON.
     *
     * @param string $key - the list's key in "data", e.g. "domains".
     *
     * @return list<array>
     */
    private static function extractList(array $response, string $key) : array
    {
        $data = is_array($response['data'] ?? null) ? $response['data'] : [];
        $list = is_array($data[$key] ?? null) ? $data[$key] : [];
        $entries = [];

        foreach ($list as $entry)
        {
            if (is_array($entry))
            {
                $entries[] = $entry;
            }
        }

        return $entries;
    }


    /**
     * Create an authenticated domain from one of SMTP2GO's sender domains, valid if both its DKIM and return-path
     * records are verified, with each that isn't.
     *
     * @param array $domain - the "domain" object of the entry, decoded from JSON.
     *
     * @return AuthenticatedDomain
     */
    private static function createDomainFromApi(array $domain) : AuthenticatedDomain
    {
        $name = (string)$domain['fulldomain'];
        $isDkimVerified = (($domain['dkim_verified'] ?? false) === true);
        $isReturnPathVerified = (($domain['rpath_verified'] ?? false) === true);
        $missing = [];

        if ($isDkimVerified === false)
        {
            $selector = (string)($domain['dkim_selector'] ?? '');
            $missing[] = "CNAME {$selector}._domainkey.{$name} → " . (string)($domain['dkim_value'] ?? '');
        }

        if ($isReturnPathVerified === false)
        {
            $selector = (string)($domain['rpath_selector'] ?? '');
            $missing[] = "CNAME {$selector}.{$name} → " . (string)($domain['rpath_value'] ?? '');
        }

        return new AuthenticatedDomain($name, $missing === [], $missing, 0);
    }


    private function createCheck(SenderCheckLevel $level, string $title, string $detail) : SenderCheck
    {
        return new SenderCheck($level, $this->m_providerName, $title, $detail);
    }


    # Accessors
    public function getProviderName() : string { return $this->m_providerName; }
}
