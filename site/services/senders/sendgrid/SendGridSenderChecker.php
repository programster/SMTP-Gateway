<?php

/*
 * The sender checks for a SendGrid provider. SendGrid refuses email from a sender it hasn't verified, either by
 * domain authentication (DNS records that let it DKIM-sign email as the domain) or by single sender verification
 * (clicking a link emailed to one address). Only domain authentication makes the email pass DMARC: with just the
 * address verified, SendGrid signs it as sendgrid.net, which doesn't match the sender's domain.
 *
 * It also checks the API key can send, and that SendGrid's event webhook is sending events here, signed with the
 * key in webhook_public_key.
 *
 * Everything is read from SendGrid's API when it's created (see fetch()), using only GET requests. The API key only
 * needs "Mail Send" to send email, so each other read it needs is optional: one it isn't allowed is skipped, saying
 * which permission would allow it.
 */

declare(strict_types = 1);


final readonly class SendGridSenderChecker implements SenderCheckerInterface
{
    // How many pages of authenticated domains and verified senders to read, at most (100 per page).
    private const int MAX_PAGES = 10;
    private const int PAGE_SIZE = 100;

    private const string API_URL = 'https://api.sendgrid.com';

    private string $m_providerName;
    private bool $m_canSend;
    private bool $m_canReadDomains;
    private bool $m_canReadSenders;
    private IdentityChecks $m_identities;
    private bool $m_canReadWebhooks;
    private SendGridWebhookCollection $m_webhooks;
    private string $m_webhookPublicKey;
    private string $m_webhookUrl;


    /**
     * @param string $providerName - the provider's name in settings.yaml.
     *
     * @param bool $canSend - whether the API key has the "Mail Send" permission.
     *
     * @param bool $canReadDomains - whether the API key could list the authenticated domains.
     *
     * @param AuthenticatedDomainCollection $domains - the authenticated domains (empty if they couldn't be
     * listed).
     *
     * @param bool $canReadSenders - whether the API key could list the verified single senders.
     *
     * @param VerifiedAddressCollection $senders - the single senders (empty if they couldn't be listed).
     *
     * @param bool $canReadWebhooks - whether the API key could list the event webhooks.
     *
     * @param SendGridWebhookCollection $webhooks - the event webhooks (empty if they couldn't be listed).
     *
     * @param string $webhookPublicKey - the provider's webhook_public_key, or an empty string if it isn't set.
     *
     * @param string $webhookUrl - where SendGrid should send its events (PUBLIC_URL/webhooks/<name>), or an empty
     * string if PUBLIC_URL isn't set.
     */
    public function __construct(
        string $providerName,
        bool $canSend,
        bool $canReadDomains,
        AuthenticatedDomainCollection $domains,
        bool $canReadSenders,
        VerifiedAddressCollection $senders,
        bool $canReadWebhooks,
        SendGridWebhookCollection $webhooks,
        string $webhookPublicKey,
        string $webhookUrl
    )
    {
        $this->m_providerName = $providerName;
        $this->m_canSend = $canSend;
        $this->m_canReadDomains = $canReadDomains;
        $this->m_canReadSenders = $canReadSenders;

        $this->m_identities = new IdentityChecks(
            $providerName,
            self::createProfile(),
            $canReadDomains,
            $domains,
            $canReadSenders,
            $senders
        );

        $this->m_canReadWebhooks = $canReadWebhooks;
        $this->m_webhooks = $webhooks;
        $this->m_webhookPublicKey = $webhookPublicKey;
        $this->m_webhookUrl = $webhookUrl;
    }


    /**
     * Read everything the checks need from SendGrid's API.
     *
     * @param string $providerName - the provider's name in settings.yaml.
     *
     * @param JsonApiClient $api - SendGrid's API, with the provider's API key (see createApiClient()).
     *
     * @param string $webhookPublicKey - the provider's webhook_public_key, or an empty string if it isn't set.
     *
     * @param string $webhookUrl - where SendGrid should send its events, or an empty string if that isn't known.
     *
     * @return SendGridSenderChecker
     *
     * @throws ProviderLookupException - if SendGrid couldn't be reached, or rejected the API key.
     */
    public static function fetch(
        string $providerName,
        JsonApiClient $api,
        string $webhookPublicKey,
        string $webhookUrl
    ) : SendGridSenderChecker
    {
        $scopes = $api->fetchJson('/v3/scopes')['scopes'] ?? [];
        $canSend = (is_array($scopes) && in_array('mail.send', $scopes, true));
        $webhooks = [];

        try
        {
            $domains = self::fetchDomains($api);
            $canReadDomains = true;
        }
        catch (ProviderPermissionException)
        {
            $domains = new AuthenticatedDomainCollection();
            $canReadDomains = false;
        }

        try
        {
            $senders = self::fetchSenders($api);
            $canReadSenders = true;
        }
        catch (ProviderPermissionException)
        {
            $senders = new VerifiedAddressCollection();
            $canReadSenders = false;
        }

        try
        {
            $entries = $api->fetchJson('/v3/user/webhooks/event/settings/all')['webhooks'] ?? [];

            foreach (is_array($entries) ? $entries : [] as $entry)
            {
                if (is_array($entry) && isset($entry['url']))
                {
                    $webhooks[] = SendGridWebhook::createFromApi($entry);
                }
            }

            $canReadWebhooks = true;
        }
        catch (ProviderPermissionException)
        {
            $canReadWebhooks = false;
        }

        return new SendGridSenderChecker(
            $providerName,
            $canSend,
            $canReadDomains,
            $domains,
            $canReadSenders,
            $senders,
            $canReadWebhooks,
            new SendGridWebhookCollection(...$webhooks),
            $webhookPublicKey,
            $webhookUrl
        );
    }


    /**
     * Create a client for SendGrid's API.
     *
     * @param string $apiKey - the API key.
     *
     * @return JsonApiClient
     */
    public static function createApiClient(string $apiKey) : JsonApiClient
    {
        return JsonApiClient::create('SendGrid', self::API_URL, ['Authorization' => "Bearer {$apiKey}"]);
    }


    public function checkAccount() : SenderCheckCollection
    {
        $checks = [];

        if ($this->m_canSend === false)
        {
            $detail =
                'SendGrid will refuse every email sent through it. Give the API key the "Mail Send" permission'
                . ' (Settings → API Keys in SendGrid).';

            $checks[] = $this->createCheck(SenderCheckLevel::ERROR, 'The API key can\'t send email', $detail);
        }
        else
        {
            $checks[] = $this->createCheck(SenderCheckLevel::OK, 'The API key can send email', '');
        }

        if ($this->m_canReadDomains === false)
        {
            $detail =
                'So whether each sender\'s domain is authenticated can\'t be checked. Giving the API key read access'
                . ' to "Sender Authentication" (whitelabel.read) would let it be.';

            $checks[] = $this->createCheck(SenderCheckLevel::WARNING, 'Can\'t list authenticated domains', $detail);
        }

        if ($this->m_canReadSenders === false)
        {
            $detail =
                'SendGrid didn\'t allow the API key to list them (GET /v3/verified_senders), so senders verified'
                . ' one address at a time can\'t be checked.';

            $checks[] = $this->createCheck(SenderCheckLevel::WARNING, 'Can\'t list verified senders', $detail);
        }

        foreach ($this->checkWebhook() as $check)
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
     * Describe how SendGrid verifies senders, for IdentityChecks.
     *
     * @return ProviderProfile
     */
    private static function createProfile() : ProviderProfile
    {
        return new ProviderProfile(
            'SendGrid',
            'Settings → Sender Authentication',
            UnverifiedSenderHandling::REFUSED,
            'The from address does not match a verified Sender Identity',
            'sendgrid.net'
        );
    }


    /**
     * Create an authenticated domain from an entry in the API's list of them (GET /v3/whitelabel/domains).
     *
     * @param array $entry - the entry, decoded from JSON.
     *
     * @return AuthenticatedDomain
     */
    private static function createDomainFromApi(array $entry) : AuthenticatedDomain
    {
        $invalidRecords = [];
        $records = (isset($entry['dns']) && is_array($entry['dns'])) ? $entry['dns'] : [];

        foreach ($records as $record)
        {
            if (is_array($record) && ($record['valid'] ?? true) === false)
            {
                $type = strtoupper((string)($record['type'] ?? ''));
                $invalidRecords[] = "{$type} {$record['host']} → {$record['data']}";
            }
        }

        return new AuthenticatedDomain(
            (string)($entry['domain'] ?? ''),
            ($entry['valid'] ?? false) === true,
            $invalidRecords,
            (int)($entry['last_validation_attempt_at'] ?? 0)
        );
    }


    /**
     * Read every authenticated domain.
     *
     * @param JsonApiClient $api - the API.
     *
     * @return AuthenticatedDomainCollection
     *
     * @throws ProviderPermissionException - if the API key isn't allowed to list them.
     *
     * @throws ProviderLookupException - if SendGrid couldn't be reached.
     */
    private static function fetchDomains(JsonApiClient $api) : AuthenticatedDomainCollection
    {
        $domains = [];
        $page = 0;
        $isMore = true;

        while ($isMore && $page < self::MAX_PAGES)
        {
            $offset = $page * self::PAGE_SIZE;
            $entries = $api->fetchJson('/v3/whitelabel/domains?limit=' . self::PAGE_SIZE . "&offset={$offset}");

            foreach ($entries as $entry)
            {
                if (is_array($entry))
                {
                    $domains[] = self::createDomainFromApi($entry);
                }
            }

            $isMore = (count($entries) === self::PAGE_SIZE);
            $page++;
        }

        return new AuthenticatedDomainCollection(...$domains);
    }


    /**
     * Read every verified single sender.
     *
     * @param JsonApiClient $api - the API.
     *
     * @return VerifiedAddressCollection
     *
     * @throws ProviderPermissionException - if the API key isn't allowed to list them.
     *
     * @throws ProviderLookupException - if SendGrid couldn't be reached.
     */
    private static function fetchSenders(JsonApiClient $api) : VerifiedAddressCollection
    {
        $senders = [];
        $page = 0;
        $lastSeenId = '';
        $isMore = true;

        while ($isMore && $page < self::MAX_PAGES)
        {
            $after = ($lastSeenId === '') ? '' : "&lastSeenID={$lastSeenId}";
            $results = $api->fetchJson('/v3/verified_senders?limit=' . self::PAGE_SIZE . $after)['results'] ?? [];
            $results = is_array($results) ? $results : [];

            foreach ($results as $result)
            {
                if (is_array($result) && isset($result['from_email']))
                {
                    $isVerified = (($result['verified'] ?? false) === true);
                    $senders[] = new VerifiedAddress((string)$result['from_email'], $isVerified);
                    $lastSeenId = (string)($result['id'] ?? '');
                }
            }

            $isMore = (count($results) === self::PAGE_SIZE && $lastSeenId !== '');
            $page++;
        }

        return new VerifiedAddressCollection(...$senders);
    }


    /**
     * Check the event webhook: that one sends to this service, is switched on, and is signed with the key in
     * webhook_public_key.
     *
     * @return SenderCheckCollection
     */
    private function checkWebhook() : SenderCheckCollection
    {
        $checks = [];
        $url = $this->m_webhookUrl;
        $isKeySet = ($this->m_webhookPublicKey !== '');
        $matching = $this->findWebhooksTo($url);

        if ($isKeySet === false && $matching->count() === 0)
        {
            $detail =
                'So deliveries, bounces and spam reports aren\'t recorded against each email. To record them, add an'
                . ' event webhook in SendGrid (Settings → Mail Settings → Event Webhooks) that posts to '
                . (($url === '') ? 'this site\'s /webhooks/' . $this->m_providerName : $url) . ', switch on its'
                . ' signature verification, and put the public key it shows in this provider\'s webhook_public_key.';

            $checks[] = $this->createCheck(SenderCheckLevel::INFO, 'Delivery events aren\'t tracked', $detail);
        }
        elseif ($this->m_canReadWebhooks === false)
        {
            $detail =
                'Giving the API key read access to the event webhook settings (user.webhooks.event.settings.read)'
                . ' would let this check that SendGrid is sending its events here, signed with webhook_public_key.';

            $checks[] = $this->createCheck(SenderCheckLevel::INFO, 'Can\'t check the event webhook', $detail);
        }
        elseif ($url === '')
        {
            $detail = 'PUBLIC_URL isn\'t set, so where SendGrid should be sending its events isn\'t known.';
            $checks[] = $this->createCheck(SenderCheckLevel::WARNING, 'Can\'t check the event webhook', $detail);
        }
        elseif ($matching->count() === 0)
        {
            $others = [];

            foreach ($this->m_webhooks as $webhook)
            {
                /* @var $webhook SendGridWebhook */
                $others[] = $webhook->getUrl();
            }

            $existing = (count($others) > 0) ? ' It has webhooks for: ' . implode(', ', $others) . '.' : '';

            $detail =
                "No event webhook in SendGrid posts to {$url}, so deliveries, bounces and spam reports aren't"
                . " recorded.{$existing} Add one (Settings → Mail Settings → Event Webhooks), with signature"
                . ' verification on.';

            $checks[] = $this->createCheck(SenderCheckLevel::ERROR, 'SendGrid isn\'t sending events here', $detail);
        }
        else
        {
            foreach ($this->describeWebhook($matching->first(), $isKeySet) as $check)
            {
                $checks[] = $check;
            }
        }

        return new SenderCheckCollection(...$checks);
    }


    /**
     * Check the event webhook that posts here.
     *
     * @param SendGridWebhook $webhook - the webhook.
     *
     * @param bool $isKeySet - whether webhook_public_key is set.
     *
     * @return SenderCheckCollection
     */
    private function describeWebhook(SendGridWebhook $webhook, bool $isKeySet) : SenderCheckCollection
    {
        $checks = [];

        if ($webhook->isEnabled() === false)
        {
            $detail = "The event webhook that posts to {$webhook->getUrl()} is switched off in SendGrid.";
            $checks[] = $this->createCheck(SenderCheckLevel::WARNING, 'The event webhook is off', $detail);
        }
        elseif ($webhook->getPublicKey() === '')
        {
            $detail =
                'Its events aren\'t signed, and this service rejects unsigned events (anyone could send them).'
                . ' Switch on signature verification for it in SendGrid, and put the public key it shows in this'
                . ' provider\'s webhook_public_key.';

            $checks[] = $this->createCheck(SenderCheckLevel::ERROR, 'The event webhook isn\'t signed', $detail);
        }
        elseif ($isKeySet === false)
        {
            $detail =
                'SendGrid signs its events, but this provider\'s webhook_public_key isn\'t set, so every event is'
                . ' rejected. Set it to the public key shown in the webhook\'s settings in SendGrid.';

            $checks[] = $this->createCheck(SenderCheckLevel::ERROR, 'Its events are all rejected', $detail);
        }
        elseif ($webhook->isSignedWith($this->m_webhookPublicKey) === false)
        {
            $detail =
                'The webhook_public_key set for this provider isn\'t the one SendGrid signs the events with, so every'
                . ' event is rejected. Copy the public key from the webhook\'s settings in SendGrid again.';

            $checks[] = $this->createCheck(SenderCheckLevel::ERROR, 'The webhook\'s public key is wrong', $detail);
        }
        else
        {
            $detail = "SendGrid posts its events to {$webhook->getUrl()}, signed with webhook_public_key.";
            $checks[] = $this->createCheck(SenderCheckLevel::OK, 'The event webhook is set up', $detail);
        }

        if ($webhook->isEnabled() && count($webhook->getMissingEvents()) > 0)
        {
            $detail =
                'It doesn\'t send ' . implode(', ', $webhook->getMissingEvents()) . ' events, so those won\'t be'
                . ' recorded against each email. Tick them in its settings in SendGrid.';

            $checks[] = $this->createCheck(SenderCheckLevel::INFO, 'Some events aren\'t sent', $detail);
        }

        return new SenderCheckCollection(...$checks);
    }


    private function findWebhooksTo(string $url) : SendGridWebhookCollection
    {
        $matching = [];

        foreach ($this->m_webhooks as $webhook)
        {
            /* @var $webhook SendGridWebhook */
            if ($url !== '' && $webhook->sendsTo($url))
            {
                $matching[] = $webhook;
            }
        }

        return new SendGridWebhookCollection(...$matching);
    }


    private function createCheck(SenderCheckLevel $level, string $title, string $detail) : SenderCheck
    {
        return new SenderCheck($level, $this->m_providerName, $title, $detail);
    }


    # Accessors
    public function getProviderName() : string { return $this->m_providerName; }
}
