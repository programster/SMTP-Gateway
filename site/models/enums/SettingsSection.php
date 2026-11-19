<?php

/*
 * The sections of settings.yaml, by their keys in it, in the order they're written.
 */

declare(strict_types = 1);


enum SettingsSection : string
{
    // The time zone that times are shown in (see ServiceSettingsParser, as are the other sections apart from the
    // providers and Google sign-in).
    case GENERAL = 'general';

    // Whether a second factor is required.
    case SECURITY = 'security';

    // What sits in front of the web interface: Cloudflare, and your own reverse proxies (see ProxySettings).
    case PROXIES = 'proxies';

    // The address the service's own emails are sent from, and how long a client waits to be told one was sent.
    case SENDING = 'sending';

    // How long each part of an email, and the logs, are kept.
    case RETENTION = 'retention';

    // Logging in to the web interface with Google (see GoogleSsoConfigParser).
    case GOOGLE_SSO = 'google_sso';

    // The providers that email is sent through, and how emails are shared between them (see ProvidersConfigParser).
    // Last, as it's the longest, and the one most often edited by hand.
    case PROVIDERS = 'providers';


    /**
     * Get the end of its page's path, e.g. "google-sso" for /admin/settings/google-sso.
     * @return string
     */
    public function getSlug() : string
    {
        return str_replace('_', '-', $this->value);
    }


    /**
     * Get its page's title, as it's shown in the menu, e.g. "Email retention".
     * @return string
     */
    public function getTitle() : string
    {
        return match ($this) {
            SettingsSection::GENERAL => 'General',
            SettingsSection::SECURITY => 'Security',
            SettingsSection::PROXIES => 'Proxies',
            SettingsSection::SENDING => 'Sending',
            SettingsSection::RETENTION => 'Email retention',
            SettingsSection::GOOGLE_SSO => 'SSO',
            SettingsSection::PROVIDERS => 'Providers',
        };
    }


    /**
     * Get the section whose page it's shown on: its own, except for the sending settings, which are a part of the
     * General page.
     *
     * @return SettingsSection
     */
    public function getPageSection() : SettingsSection
    {
        return ($this === SettingsSection::SENDING) ? SettingsSection::GENERAL : $this;
    }


    /**
     * Get the path of the page it's shown on, e.g. "/admin/settings/general", or "/admin/settings/general#sending" for
     * the sending settings, which are a part of it. The providers have a page of their own, with an editor for them.
     *
     * @return string
     */
    public function getPagePath() : string
    {
        $pageSection = $this->getPageSection();

        $path = ($this === SettingsSection::PROVIDERS)
            ? '/providers'
            : "/admin/settings/{$pageSection->getSlug()}";

        return ($pageSection === $this) ? $path : "{$path}#{$this->value}";
    }


    /**
     * Get the path its form is saved to, e.g. "/admin/settings/sending", which is its own, even when it's shown on
     * another section's page.
     *
     * @return string
     */
    public function getSavePath() : string
    {
        return ($this === SettingsSection::PROVIDERS) ? '/providers' : "/admin/settings/{$this->getSlug()}";
    }
}
