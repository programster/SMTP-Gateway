<?php

/*
 * One provider in settings.yaml: its id, its name, whether it's enabled, its settings, its own rate limits, and the
 * senders it takes.
 */

declare(strict_types = 1);


readonly class ProviderDefinition
{
    private const string NAME_PATTERN = '/^[a-z0-9][a-z0-9_-]{0,49}$/';

    // The names the web interface uses for its own pages under /providers, where each provider has a page too.
    private const array RESERVED_NAMES = ['new', 'export', 'sender-checks', 'mode', 'order'];

    private ProviderId $m_id;
    private string $m_name;
    private bool $m_isEnabled;
    private ProviderSettingsInterface $m_settings;
    private RateLimitSet $m_rateLimits;
    private AddressRestrictionInterface $m_allowedSenders;


    /**
     * @param ProviderId $id - its unique id, which stays the same when it's renamed, so the email tokens restricted to
     * it stay linked to it.
     *
     * @param string $name - a unique name for it, from 1 to 50 lowercase letters, digits, "-" and "_", e.g.
     * "sendgrid-main". Its event webhook (if it has one) is at /webhooks/<name>, and its emails are recorded
     * against it, so renaming it starts its statistics and rate limits afresh. It can't be one of the names the web
     * interface's pages use: "new", "export", "sender-checks", "mode" or "order".
     *
     * @param bool $isEnabled - whether emails can be sent through it.
     *
     * @param ProviderSettingsInterface $settings - its type, and the settings for that type.
     *
     * @param RateLimitSet $rateLimits - its own rate limits, which apply on top of the global and per-key ones.
     * Empty for no limit.
     *
     * @param AddressRestrictionInterface $allowedSenders - the senders it takes: anyone (AnyAddress), or anyone at
     * the domains, or with the addresses, in an AddressRuleSet. An email's envelope sender, and the addresses in its
     * From and Sender headers, must all be taken for it to be sent through it.
     *
     * @throws InvalidInputException - if the name isn't valid, or is one of the web interface's.
     */
    public function __construct(
        ProviderId $id,
        string $name,
        bool $isEnabled,
        ProviderSettingsInterface $settings,
        RateLimitSet $rateLimits,
        AddressRestrictionInterface $allowedSenders
    )
    {
        if (preg_match(self::NAME_PATTERN, $name) !== 1)
        {
            $msg =
                "\"{$name}\" isn't a valid provider name. It must be from 1 to 50 lowercase letters, digits, \"-\""
                . ' and "_", starting with a letter or digit, e.g. "sendgrid-main".';

            throw new InvalidInputException($msg);
        }

        if (in_array($name, self::RESERVED_NAMES, true))
        {
            $msg = "\"{$name}\" can't be a provider's name, as the web interface uses /providers/{$name} for a page.";
            throw new InvalidInputException($msg);
        }

        $this->m_id = $id;
        $this->m_name = $name;
        $this->m_isEnabled = $isEnabled;
        $this->m_settings = $settings;
        $this->m_rateLimits = $rateLimits;
        $this->m_allowedSenders = $allowedSenders;
    }


    /**
     * Get the names a provider can't have, as the web interface uses /providers/<name> for a page, e.g. for the
     * provider form to check a name as it's typed.
     *
     * @return string[]
     */
    public static function getReservedNames() : array
    {
        return self::RESERVED_NAMES;
    }


    /**
     * Check whether it takes an email sent as these addresses: its envelope sender, and the addresses in its From and
     * Sender headers (see RawEmail::getSenderAddresses()). Every one must be taken.
     *
     * @param EmailAddressCollection $senders - the addresses.
     *
     * @return bool
     */
    public function takesAll(EmailAddressCollection $senders) : bool
    {
        return $this->m_allowedSenders->allowsAll($senders);
    }


    public function getType() : ProviderType
    {
        return $this->m_settings->getType();
    }


    # Accessors
    public function getId() : ProviderId { return $this->m_id; }
    public function getName() : string { return $this->m_name; }
    public function isEnabled() : bool { return $this->m_isEnabled; }
    public function getSettings() : ProviderSettingsInterface { return $this->m_settings; }
    public function getRateLimits() : RateLimitSet { return $this->m_rateLimits; }
    public function getAllowedSenders() : AddressRestrictionInterface { return $this->m_allowedSenders; }
}
