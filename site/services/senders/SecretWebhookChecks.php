<?php

/*
 * Checks the webhook of a provider whose requests are authenticated with its webhook_secret, either as their
 * password (for a provider that doesn't sign them, see WebhookBasicAuth) or by signing them with it: that a webhook
 * in its account posts to this service, with the secret, and sends the events that are recorded against each email.
 */

declare(strict_types = 1);


final readonly class SecretWebhookChecks
{
    private string $m_providerName;
    private string $m_label;
    private string $m_where;
    private WebhookAuthentication $m_authentication;
    private bool $m_canRead;
    private bool $m_canReadSecret;
    private ConfiguredWebhookCollection $m_webhooks;
    private string $m_secret;
    private string $m_webhookUrl;
    private array $m_recordedEvents;
    private array $m_essentialEvents;


    /**
     * @param string $providerName - the provider's name in settings.yaml (each check's source).
     *
     * @param string $label - the provider's own name, e.g. "Postmark".
     *
     * @param string $where - where in the provider's web interface its webhooks are set up, e.g. "Transactional →
     * Settings → Webhook".
     *
     * @param WebhookAuthentication $authentication - how its webhook uses the secret.
     *
     * @param bool $canRead - whether its webhooks could be listed.
     *
     * @param bool $canReadSecret - whether its API shows each webhook's secret, so it can be checked.
     *
     * @param ConfiguredWebhookCollection $webhooks - its webhooks (empty if they couldn't be listed).
     *
     * @param string $secret - the provider's webhook_secret, or an empty string if it isn't set.
     *
     * @param string $webhookUrl - where it should post its events (see ProviderWebhookUrl), or an empty string if
     * PUBLIC_URL isn't set.
     *
     * @param list<string> $recordedEvents - the events that are recorded, in the provider's own names.
     *
     * @param list<string> $essentialEvents - of those, the ones without which deliveries and bounces go unrecorded.
     */
    public function __construct(
        string $providerName,
        string $label,
        string $where,
        WebhookAuthentication $authentication,
        bool $canRead,
        bool $canReadSecret,
        ConfiguredWebhookCollection $webhooks,
        string $secret,
        string $webhookUrl,
        array $recordedEvents,
        array $essentialEvents
    )
    {
        $this->m_providerName = $providerName;
        $this->m_label = $label;
        $this->m_where = $where;
        $this->m_authentication = $authentication;
        $this->m_canRead = $canRead;
        $this->m_canReadSecret = $canReadSecret;
        $this->m_webhooks = $webhooks;
        $this->m_secret = $secret;
        $this->m_webhookUrl = $webhookUrl;
        $this->m_recordedEvents = $recordedEvents;
        $this->m_essentialEvents = $essentialEvents;
    }


    /**
     * Check the webhook.
     *
     * @return SenderCheckCollection
     */
    public function check() : SenderCheckCollection
    {
        $checks = [];
        $url = $this->m_webhookUrl;
        $label = $this->m_label;
        $matching = $this->m_webhooks->filterPostingTo($url);

        if ($this->m_secret === '' && $matching->count() === 0)
        {
            $first = ($this->m_authentication === WebhookAuthentication::PASSWORD)
                ? 'set this provider\'s webhook_secret to a long random value, and '
                : '';

            $detail =
                'So deliveries, bounces and spam reports aren\'t recorded against each email. To record them, '
                . $first . $this->describeSetUp();

            $checks[] = $this->createCheck(SenderCheckLevel::INFO, 'Delivery events aren\'t tracked', $detail);
        }
        elseif ($this->m_canRead === false)
        {
            $detail =
                "The API key isn't allowed to list {$label}'s webhooks, so whether they post here, with the"
                . ' webhook_secret, can\'t be checked.';

            $checks[] = $this->createCheck(SenderCheckLevel::INFO, 'Can\'t check the webhook', $detail);
        }
        elseif ($url === '')
        {
            $detail = "PUBLIC_URL isn't set, so where {$label} should be sending its events isn't known.";
            $checks[] = $this->createCheck(SenderCheckLevel::WARNING, 'Can\'t check the webhook', $detail);
        }
        elseif ($matching->count() === 0)
        {
            $detail = "No webhook posts to {$url}, so its events aren't recorded. To record them, "
                . $this->describeSetUp();

            $checks[] = $this->createCheck(SenderCheckLevel::ERROR, "{$label} isn't sending events here", $detail);
        }
        else
        {
            foreach ($this->describe($matching->first()) as $check)
            {
                $checks[] = $check;
            }
        }

        return new SenderCheckCollection(...$checks);
    }


    /**
     * Check the webhook that posts here.
     *
     * @param ConfiguredWebhook $webhook - the webhook.
     *
     * @return SenderCheckCollection
     */
    private function describe(ConfiguredWebhook $webhook) : SenderCheckCollection
    {
        $checks = [];
        $missing = array_values(array_diff($this->m_recordedEvents, $webhook->getEvents()));
        $missingEssential = array_values(array_intersect($this->m_essentialEvents, $missing));

        if ($this->m_secret === '')
        {
            $detail =
                "{$this->m_label} posts its events here, but this provider's webhook_secret isn't set, so they're"
                . " all refused. Set it, and {$this->describeSetUp()}";

            $checks[] = $this->createCheck(SenderCheckLevel::ERROR, 'Its events are all refused', $detail);
        }
        elseif ($this->m_canReadSecret && $webhook->hasSecret($this->m_secret) === false)
        {
            $isPassword = ($this->m_authentication === WebhookAuthentication::PASSWORD);
            $what = $isPassword ? 'password' : 'signing secret';
            $fix = $isPassword ? 'Set it to the webhook_secret.' : 'Copy it into the webhook_secret.';

            $detail =
                "The webhook's {$what} in {$this->m_label} isn't this provider's webhook_secret, so its events are"
                . " all refused. {$fix}";

            $checks[] = $this->createCheck(SenderCheckLevel::ERROR, "The webhook's {$what} is wrong", $detail);
        }
        elseif (count($missingEssential) > 0)
        {
            $detail =
                'The webhook doesn\'t send ' . implode(' or ', $missingEssential) . ' events, so they aren\'t'
                . " recorded. Tick them in its settings in {$this->m_label} ({$this->m_where}).";

            $checks[] = $this->createCheck(SenderCheckLevel::ERROR, 'Delivery events aren\'t sent', $detail);
        }
        elseif ($this->m_canReadSecret === false)
        {
            $detail =
                "{$this->m_label} posts its events to {$webhook->getUrl()}. Its API doesn't show the webhook's"
                . ' secret, so whether it\'s the webhook_secret can\'t be checked: if its events are refused, the'
                . ' log says so.';

            $checks[] = $this->createCheck(SenderCheckLevel::OK, 'The webhook is set up', $detail);
        }
        else
        {
            $detail = "{$this->m_label} posts its events to {$webhook->getUrl()}, with the webhook_secret.";
            $checks[] = $this->createCheck(SenderCheckLevel::OK, 'The webhook is set up', $detail);
        }

        $isSecretRight = ($this->m_canReadSecret === false || $webhook->hasSecret($this->m_secret));

        if ($this->m_secret !== '' && $isSecretRight && count($missing) > 0 && count($missingEssential) === 0)
        {
            $detail =
                'It doesn\'t send ' . implode(', ', $missing) . ' events, so those won\'t be recorded against each'
                . " email. Tick them in its settings in {$this->m_label}.";

            $checks[] = $this->createCheck(SenderCheckLevel::INFO, 'Some events aren\'t sent', $detail);
        }

        return new SenderCheckCollection(...$checks);
    }


    private function describeSetUp() : string
    {
        $url = $this->m_webhookUrl;
        $here = ($url === '') ? 'this site\'s /webhooks/' . $this->m_providerName : $url;

        $basicAuth =
            "add a webhook in {$this->m_label} ({$this->m_where}) that posts to {$here}, with Basic auth: the"
            . ' username "' . WebhookBasicAuth::USERNAME . '", and this provider\'s webhook_secret as the password'
            . ' (e.g. https://' . WebhookBasicAuth::USERNAME . ':<webhook_secret>@...).';

        $signed =
            "add a webhook in {$this->m_label} ({$this->m_where}) that posts to {$here}, and put the signing secret"
            . ' it shows in this provider\'s webhook_secret.';

        return ($this->m_authentication === WebhookAuthentication::PASSWORD) ? $basicAuth : $signed;
    }


    private function createCheck(SenderCheckLevel $level, string $title, string $detail) : SenderCheck
    {
        return new SenderCheck($level, $this->m_providerName, $title, $detail);
    }
}
