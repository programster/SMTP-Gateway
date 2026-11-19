<?php

/*
 * One provider in settings.yaml: its name, whether it's enabled, its settings, and its own rate limits.
 */

declare(strict_types = 1);


readonly class ProviderDefinition
{
    private const string NAME_PATTERN = '/^[a-z0-9][a-z0-9_-]{0,49}$/';

    // The names the web interface uses for its own pages under /providers, where each provider has a page too.
    private const array RESERVED_NAMES = ['new', 'export', 'sender-checks', 'mode', 'order'];

    private string $m_name;
    private bool $m_isEnabled;
    private ProviderSettingsInterface $m_settings;
    private RateLimitSet $m_rateLimits;


    /**
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
     * @throws InvalidInputException - if the name isn't valid, or is one of the web interface's.
     */
    public function __construct(
        string $name,
        bool $isEnabled,
        ProviderSettingsInterface $settings,
        RateLimitSet $rateLimits
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

        $this->m_name = $name;
        $this->m_isEnabled = $isEnabled;
        $this->m_settings = $settings;
        $this->m_rateLimits = $rateLimits;
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


    public function getType() : ProviderType
    {
        return $this->m_settings->getType();
    }


    # Accessors
    public function getName() : string { return $this->m_name; }
    public function isEnabled() : bool { return $this->m_isEnabled; }
    public function getSettings() : ProviderSettingsInterface { return $this->m_settings; }
    public function getRateLimits() : RateLimitSet { return $this->m_rateLimits; }
}
