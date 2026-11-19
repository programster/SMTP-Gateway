<?php

/*
 * The sender checks for a Postmark provider. Postmark refuses email from a sender that isn't a verified "sender
 * signature": either a domain with its DKIM record in place, or one address that clicked a confirmation link (which
 * Postmark then signs as its own pm.mtasv.net, so it fails DMARC). A domain's Return-Path record (a CNAME to
 * pm.mtasv.net) also makes SPF align with it, which DMARC doesn't need, but some receivers like.
 *
 * Postmark's domains and sender signatures can only be read with an account token (account_token), so they're
 * only checked when it's set. The server token, which sends the email, is enough to check SMTP is switched on, the
 * server isn't in sandbox mode, and its webhook posts here with the webhook_secret.
 *
 * Postmark's DKIMVerified stays true once a domain has been verified, even if its record is later removed from
 * DNS, so the DNS checks are what show it's still there.
 */

declare(strict_types = 1);


final readonly class PostmarkSenderChecker implements SenderCheckerInterface
{
    // How many pages of domains and sender signatures to read, at most (500 per page, Postmark's most).
    private const int MAX_PAGES = 4;
    private const int PAGE_SIZE = 500;

    private const string API_URL = 'https://api.postmarkapp.com';

    // The webhook triggers whose events are recorded, and the ones without which bounces go unrecorded.
    private const array RECORDED_TRIGGERS = [
        'Delivery', 'Bounce', 'SpamComplaint', 'Open', 'Click', 'SubscriptionChange',
    ];

    private const array ESSENTIAL_TRIGGERS = ['Delivery', 'Bounce'];

    private string $m_providerName;
    private bool $m_hasAccountToken;
    private IdentityChecks $m_identities;
    private AuthenticatedDomainCollection $m_unalignedReturnPaths;
    private bool $m_isSmtpEnabled;
    private bool $m_isSandbox;
    private SecretWebhookChecks $m_webhookChecks;


    /**
     * @param string $providerName - the provider's name in settings.yaml.
     *
     * @param bool $hasAccountToken - whether an account token was given, so the domains and signatures were read.
     *
     * @param AuthenticatedDomainCollection $domains - the domains, valid if their DKIM is verified.
     *
     * @param VerifiedAddressCollection $signatures - the single-address sender signatures.
     *
     * @param AuthenticatedDomainCollection $unalignedReturnPaths - the domains whose Return-Path isn't verified.
     *
     * @param bool $isSmtpEnabled - whether SMTP is switched on for the server.
     *
     * @param bool $isSandbox - whether the server is in sandbox mode (it never delivers anything).
     *
     * @param string $messageStream - the stream the provider sends into.
     *
     * @param ConfiguredWebhookCollection $webhooks - the stream's webhooks.
     *
     * @param string $webhookSecret - the provider's webhook_secret, or an empty string if it isn't set.
     *
     * @param string $webhookUrl - where Postmark should send its events, or an empty string if that isn't known.
     */
    public function __construct(
        string $providerName,
        bool $hasAccountToken,
        AuthenticatedDomainCollection $domains,
        VerifiedAddressCollection $signatures,
        AuthenticatedDomainCollection $unalignedReturnPaths,
        bool $isSmtpEnabled,
        bool $isSandbox,
        string $messageStream,
        ConfiguredWebhookCollection $webhooks,
        string $webhookSecret,
        string $webhookUrl
    )
    {
        $this->m_providerName = $providerName;
        $this->m_hasAccountToken = $hasAccountToken;

        $this->m_identities = new IdentityChecks(
            $providerName,
            self::createProfile(),
            $hasAccountToken,
            $domains,
            $hasAccountToken,
            $signatures
        );

        $this->m_unalignedReturnPaths = $unalignedReturnPaths;
        $this->m_isSmtpEnabled = $isSmtpEnabled;
        $this->m_isSandbox = $isSandbox;

        $this->m_webhookChecks = new SecretWebhookChecks(
            $providerName,
            'Postmark',
            "Servers → the server → the \"{$messageStream}\" stream → Webhooks",
            WebhookAuthentication::PASSWORD,
            true,
            true,
            $webhooks,
            $webhookSecret,
            $webhookUrl,
            self::RECORDED_TRIGGERS,
            self::ESSENTIAL_TRIGGERS
        );
    }


    /**
     * Read everything the checks need from Postmark's API.
     *
     * @param string $providerName - the provider's name in settings.yaml.
     *
     * @param JsonApiClient $serverApi - Postmark's API, with the server token (see createServerApiClient()).
     *
     * @param JsonApiClient|null $accountApi - Postmark's API, with an account token (see createAccountApiClient()),
     * or null if there isn't one, so the domains and signatures can't be read.
     *
     * @param string $messageStream - the stream the provider sends into.
     *
     * @param string $webhookSecret - the provider's webhook_secret, or an empty string if it isn't set.
     *
     * @param string $webhookUrl - where Postmark should send its events, or an empty string if that isn't known.
     *
     * @return PostmarkSenderChecker
     *
     * @throws ProviderLookupException - if Postmark couldn't be reached, or rejected a token.
     */
    public static function fetch(
        string $providerName,
        JsonApiClient $serverApi,
        ?JsonApiClient $accountApi,
        string $messageStream,
        string $webhookSecret,
        string $webhookUrl
    ) : PostmarkSenderChecker
    {
        $server = $serverApi->fetchJson('/server');
        $hooks = $serverApi->fetchJson('/webhooks?MessageStream=' . rawurlencode($messageStream))['Webhooks'] ?? [];
        $webhooks = [];

        foreach (is_array($hooks) ? $hooks : [] as $hook)
        {
            if (is_array($hook) && is_string($hook['Url'] ?? null))
            {
                $webhooks[] = self::createWebhookFromApi($hook);
            }
        }

        $domains = [];
        $unalignedReturnPaths = [];
        $signatures = [];

        if ($accountApi !== null)
        {
            foreach (self::fetchPages($accountApi, '/domains', 'Domains') as $entry)
            {
                $isDkimVerified = (($entry['DKIMVerified'] ?? false) === true);
                $missing = $isDkimVerified ? [] : ["the DKIM TXT record for {$entry['Name']}"];
                $domain = new AuthenticatedDomain((string)$entry['Name'], $isDkimVerified, $missing, 0);
                $domains[] = $domain;

                if (($entry['ReturnPathDomainVerified'] ?? false) !== true)
                {
                    $unalignedReturnPaths[] = $domain;
                }
            }

            foreach (self::fetchPages($accountApi, '/senders', 'SenderSignatures') as $entry)
            {
                $isConfirmed = (($entry['Confirmed'] ?? false) === true);
                $signatures[] = new VerifiedAddress((string)($entry['EmailAddress'] ?? ''), $isConfirmed);
            }
        }

        return new PostmarkSenderChecker(
            $providerName,
            $accountApi !== null,
            new AuthenticatedDomainCollection(...$domains),
            new VerifiedAddressCollection(...$signatures),
            new AuthenticatedDomainCollection(...$unalignedReturnPaths),
            ($server['SmtpApiActivated'] ?? true) === true,
            ($server['DeliveryType'] ?? '') === 'Sandbox',
            $messageStream,
            new ConfiguredWebhookCollection(...$webhooks),
            $webhookSecret,
            $webhookUrl
        );
    }


    /**
     * Create a client for Postmark's server API.
     *
     * @param string $serverToken - the server's API token.
     *
     * @return JsonApiClient
     */
    public static function createServerApiClient(string $serverToken) : JsonApiClient
    {
        return JsonApiClient::create('Postmark', self::API_URL, ['X-Postmark-Server-Token' => $serverToken]);
    }


    /**
     * Create a client for Postmark's account API.
     *
     * @param string $accountToken - the account's API token.
     *
     * @return JsonApiClient
     */
    public static function createAccountApiClient(string $accountToken) : JsonApiClient
    {
        return JsonApiClient::create('Postmark', self::API_URL, ['X-Postmark-Account-Token' => $accountToken]);
    }


    public function checkAccount() : SenderCheckCollection
    {
        $checks = [];

        if ($this->m_isSmtpEnabled === false)
        {
            $detail =
                'Postmark refuses every email sent through it. Switch SMTP on in the server\'s settings in Postmark'
                . ' (Servers → the server → Settings → SMTP).';

            $checks[] = $this->createCheck(SenderCheckLevel::ERROR, 'SMTP is switched off for the server', $detail);
        }
        else
        {
            $checks[] = $this->createCheck(SenderCheckLevel::OK, 'The server accepts email over SMTP', '');
        }

        if ($this->m_isSandbox)
        {
            $detail =
                'Postmark accepts its email, and reports it delivered, but never sends it. Switch the server to live'
                . ' in its settings in Postmark.';

            $checks[] = $this->createCheck(SenderCheckLevel::WARNING, 'The server is in sandbox mode', $detail);
        }

        if ($this->m_hasAccountToken === false)
        {
            $detail =
                'Postmark only lets its domains and sender signatures be read with an account API token (Account →'
                . ' API Tokens), so whether each sender is verified can\'t be checked. Set this provider\'s'
                . ' account_token to one to check them.';

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
        return $this->m_identities->checkDomain($dns)->merge($this->checkReturnPaths($dns));
    }


    public function checkAddress(EmailAddress $address, DomainDns $dns) : SenderCheckCollection
    {
        return $this->m_identities->checkAddress($address, $dns)->merge($this->checkReturnPaths($dns));
    }


    public function checkAnySender() : SenderCheckCollection
    {
        return $this->m_identities->checkAnySender();
    }


    /**
     * Describe how Postmark verifies senders, for IdentityChecks.
     *
     * @return ProviderProfile
     */
    private static function createProfile() : ProviderProfile
    {
        return new ProviderProfile(
            'Postmark',
            'Sender Signatures → Domains',
            UnverifiedSenderHandling::REFUSED,
            'The \'From\' address you supplied is not a Sender Signature on your account',
            'pm.mtasv.net'
        );
    }


    /**
     * Read every page of a list in Postmark's account API.
     *
     * @param JsonApiClient $api - the account API.
     *
     * @param string $path - the list, e.g. "/domains".
     *
     * @param string $key - the key the entries are under, e.g. "Domains".
     *
     * @return list<array> - the entries, each decoded from JSON, and each with a "Name" or "EmailAddress".
     *
     * @throws ProviderLookupException - if Postmark couldn't be reached, or rejected the token.
     */
    private static function fetchPages(JsonApiClient $api, string $path, string $key) : array
    {
        $entries = [];
        $page = 0;
        $isMore = true;

        while ($isMore && $page < self::MAX_PAGES)
        {
            $offset = $page * self::PAGE_SIZE;
            $data = $api->fetchJson("{$path}?count=" . self::PAGE_SIZE . "&offset={$offset}");
            $pageEntries = is_array($data[$key] ?? null) ? $data[$key] : [];

            foreach ($pageEntries as $entry)
            {
                $isNamed = (is_string($entry['Name'] ?? null) || is_string($entry['EmailAddress'] ?? null));

                if (is_array($entry) && $isNamed)
                {
                    $entries[] = $entry;
                }
            }

            $isMore = (count($pageEntries) === self::PAGE_SIZE);
            $page++;
        }

        return $entries;
    }


    /**
     * Note a sender's domain whose Return-Path isn't verified, if its DKIM is.
     * @param DomainDns $dns - the sender's domain's records.
     * @return SenderCheckCollection - a check for each such domain that covers it, or none.
     */
    private function checkReturnPaths(DomainDns $dns) : SenderCheckCollection
    {
        $checks = [];

        foreach ($this->m_unalignedReturnPaths as $domain)
        {
            /* @var $domain AuthenticatedDomain */
            if ($domain->isValid() && $domain->covers($dns->getDomain()))
            {
                $detail =
                    "Postmark's bounces for {$domain->getDomain()} come back to pm.mtasv.net, so SPF doesn't align"
                    . ' with it. DMARC still passes, through DKIM, but adding the Return-Path CNAME Postmark shows for'
                    . ' it (Sender Signatures → Domains) makes both align.';

                $title = "{$domain->getDomain()}'s Return-Path isn't verified";
                $checks[] = $this->createCheck(SenderCheckLevel::INFO, $title, $detail);
            }
        }

        return new SenderCheckCollection(...$checks);
    }


    /**
     * Create a configured webhook from an entry in GET /webhooks. Its password is in its HttpAuth, or its address.
     *
     * @param array $entry - the entry, decoded from JSON.
     *
     * @return ConfiguredWebhook
     */
    private static function createWebhookFromApi(array $entry) : ConfiguredWebhook
    {
        $url = (string)$entry['Url'];
        $auth = is_array($entry['HttpAuth'] ?? null) ? $entry['HttpAuth'] : [];
        $authPassword = is_string($auth['Password'] ?? null) ? $auth['Password'] : '';
        $password = ($authPassword !== '') ? $authPassword : WebhookBasicAuth::getPasswordInUrl($url);
        $triggers = is_array($entry['Triggers'] ?? null) ? $entry['Triggers'] : [];
        $enabled = [];

        foreach ($triggers as $trigger => $settings)
        {
            if (is_array($settings) && ($settings['Enabled'] ?? false) === true)
            {
                $enabled[] = (string)$trigger;
            }
        }

        return new ConfiguredWebhook($url, $password, $enabled);
    }


    private function createCheck(SenderCheckLevel $level, string $title, string $detail) : SenderCheck
    {
        return new SenderCheck($level, $this->m_providerName, $title, $detail);
    }


    # Accessors
    public function getProviderName() : string { return $this->m_providerName; }
}
