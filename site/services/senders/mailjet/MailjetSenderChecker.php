<?php

/*
 * The sender checks for a Mailjet provider. Mailjet only sends from a sender that has been validated: one address,
 * or a whole domain ("*@example.org"). Only a domain whose DKIM record Mailjet has found is signed as itself, so
 * passes DMARC; any other sender is signed as Mailjet's own domain.
 *
 * Mailjet's API is read with the same key pair that sends the email, so no other key is needed. Senders aren't
 * shared between an account's API keys (sub-accounts), so these are the senders of the key that sends. Everything
 * is read with GET requests, when it's created.
 */

declare(strict_types = 1);


final readonly class MailjetSenderChecker implements SenderCheckerInterface
{
    private const string API_URL = 'https://api.mailjet.com';

    // The most senders and domains read (Mailjet allows 100 senders per key).
    private const int LIMIT = 1000;

    // The event types whose callbacks are recorded, and the ones without which deliveries and bounces aren't.
    private const array RECORDED_EVENTS = ['sent', 'bounce', 'blocked', 'spam', 'unsub', 'open', 'click'];
    private const array ESSENTIAL_EVENTS = ['sent', 'bounce', 'blocked'];

    private string $m_providerName;
    private IdentityChecks $m_identities;
    private SecretWebhookChecks $m_webhookChecks;


    /**
     * @param string $providerName - the provider's name in settings.yaml.
     *
     * @param AuthenticatedDomainCollection $domains - the key's domains, valid if Mailjet has found their DKIM.
     *
     * @param VerifiedAddressCollection $senders - its single-address senders, verified if they're active.
     *
     * @param ConfiguredWebhookCollection $webhooks - its event callbacks, one per address.
     *
     * @param string $webhookSecret - the provider's webhook_secret, or an empty string if it isn't set.
     *
     * @param string $webhookUrl - where Mailjet should send its events, or an empty string if that isn't known.
     */
    public function __construct(
        string $providerName,
        AuthenticatedDomainCollection $domains,
        VerifiedAddressCollection $senders,
        ConfiguredWebhookCollection $webhooks,
        string $webhookSecret,
        string $webhookUrl
    )
    {
        $this->m_providerName = $providerName;
        $profile = self::createProfile();
        $this->m_identities = new IdentityChecks($providerName, $profile, true, $domains, true, $senders);

        $this->m_webhookChecks = new SecretWebhookChecks(
            $providerName,
            'Mailjet',
            'Account settings → Event tracking (triggers)',
            WebhookAuthentication::PASSWORD,
            true,
            true,
            $webhooks,
            $webhookSecret,
            $webhookUrl,
            self::RECORDED_EVENTS,
            self::ESSENTIAL_EVENTS
        );
    }


    /**
     * Read everything the checks need from Mailjet's API.
     *
     * @param string $providerName - the provider's name in settings.yaml.
     *
     * @param JsonApiClient $api - Mailjet's API, with the provider's key pair (see createApiClient()).
     *
     * @param string $webhookSecret - the provider's webhook_secret, or an empty string if it isn't set.
     *
     * @param string $webhookUrl - where Mailjet should send its events, or an empty string if that isn't known.
     *
     * @return MailjetSenderChecker
     *
     * @throws ProviderLookupException - if Mailjet couldn't be reached, or rejected the key pair.
     */
    public static function fetch(
        string $providerName,
        JsonApiClient $api,
        string $webhookSecret,
        string $webhookUrl
    ) : MailjetSenderChecker
    {
        $domains = [];
        $senders = [];

        foreach (self::fetchData($api, '/v3/REST/dns?Limit=' . self::LIMIT) as $entry)
        {
            if (is_string($entry['Domain'] ?? null))
            {
                $domains[] = self::createDomainFromApi($entry);
            }
        }

        foreach (self::fetchData($api, '/v3/REST/sender?Limit=' . self::LIMIT) as $entry)
        {
            $email = (string)($entry['Email'] ?? '');

            if ($email !== '' && !str_starts_with($email, '*@') && ($entry['Status'] ?? '') !== 'Deleted')
            {
                $senders[] = new VerifiedAddress($email, ($entry['Status'] ?? '') === 'Active');
            }
        }

        $events = [];

        foreach (self::fetchData($api, '/v3/REST/eventcallbackurl?Limit=' . self::LIMIT) as $entry)
        {
            $url = (string)($entry['Url'] ?? '');

            // Each event type has its own callback, so they're grouped by address, leaving out any Mailjet has
            // given up on ("dead"), and backups.
            $isLive = (($entry['Status'] ?? 'alive') === 'alive' && ($entry['IsBackup'] ?? false) !== true);

            if ($url !== '' && $isLive)
            {
                $events[$url][] = (string)($entry['EventType'] ?? '');
            }
        }

        $webhooks = [];

        foreach ($events as $url => $types)
        {
            $password = WebhookBasicAuth::getPasswordInUrl((string)$url);
            $webhooks[] = new ConfiguredWebhook((string)$url, $password, $types);
        }

        return new MailjetSenderChecker(
            $providerName,
            new AuthenticatedDomainCollection(...$domains),
            new VerifiedAddressCollection(...$senders),
            new ConfiguredWebhookCollection(...$webhooks),
            $webhookSecret,
            $webhookUrl
        );
    }


    /**
     * Create a client for Mailjet's API.
     *
     * @param string $apiKey - the (public) API key.
     *
     * @param string $secretKey - its secret key.
     *
     * @return JsonApiClient
     */
    public static function createApiClient(string $apiKey, string $secretKey) : JsonApiClient
    {
        $authorization = 'Basic ' . base64_encode("{$apiKey}:{$secretKey}");
        return JsonApiClient::create('Mailjet', self::API_URL, ['Authorization' => $authorization]);
    }


    public function checkAccount() : SenderCheckCollection
    {
        return $this->m_webhookChecks->check();
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
     * Describe how Mailjet verifies senders, for IdentityChecks.
     *
     * @return ProviderProfile
     */
    private static function createProfile() : ProviderProfile
    {
        return new ProviderProfile(
            'Mailjet',
            'Account settings → Domains & senders',
            UnverifiedSenderHandling::REFUSED,
            '',
            'Mailjet\'s own domain'
        );
    }


    /**
     * Read one of Mailjet's lists.
     *
     * @param JsonApiClient $api - the API.
     *
     * @param string $path - the list, with its query string.
     *
     * @return list<array> - its entries ("Data"), each decoded from JSON.
     *
     * @throws ProviderLookupException - if Mailjet couldn't be reached, or rejected the key pair.
     */
    private static function fetchData(JsonApiClient $api, string $path) : array
    {
        $entries = [];
        $data = $api->fetchJson($path)['Data'] ?? [];

        foreach (is_array($data) ? $data : [] as $entry)
        {
            if (is_array($entry))
            {
                $entries[] = $entry;
            }
        }

        return $entries;
    }


    /**
     * Create an authenticated domain from an entry in GET /v3/REST/dns, which is valid if Mailjet has found its
     * DKIM record.
     *
     * @param array $entry - the entry, decoded from JSON.
     *
     * @return AuthenticatedDomain
     */
    private static function createDomainFromApi(array $entry) : AuthenticatedDomain
    {
        $isDkimOk = (($entry['DKIMStatus'] ?? '') === 'OK');
        $recordName = rtrim((string)($entry['DKIMRecordName'] ?? ''), '.');
        $value = (string)($entry['DKIMRecordValue'] ?? '');
        $shortValue = (strlen($value) > 40) ? substr($value, 0, 40) . '…' : $value;
        $missing = ($isDkimOk || $recordName === '') ? [] : ["TXT {$recordName} → {$shortValue}"];
        $checkedAt = strtotime((string)($entry['LastCheckAt'] ?? ''));
        $lastCheckedAt = is_int($checkedAt) ? $checkedAt : 0;
        return new AuthenticatedDomain((string)$entry['Domain'], $isDkimOk, $missing, $lastCheckedAt);
    }


    # Accessors
    public function getProviderName() : string { return $this->m_providerName; }
}
