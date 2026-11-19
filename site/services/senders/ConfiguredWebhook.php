<?php

/*
 * A webhook set up in a provider's account, as its API describes it: where it posts, the password (or token) it
 * authenticates with, if it's one that doesn't sign its requests (see WebhookBasicAuth), and the events it sends.
 */

declare(strict_types = 1);


final readonly class ConfiguredWebhook
{
    private string $m_url;
    private string $m_secret;
    private array $m_events;


    /**
     * @param string $url - where it posts to. Any username and password in it are removed.
     *
     * @param string $secret - the password or token it authenticates with, or an empty string if it has none.
     *
     * @param list<string> $events - the events it sends, in the provider's own names, e.g. "Delivery".
     */
    public function __construct(string $url, string $secret, array $events)
    {
        $this->m_url = WebhookBasicAuth::removeCredentials($url);
        $this->m_secret = $secret;
        $this->m_events = $events;
    }


    /**
     * Check whether it posts to an address, ignoring any username and password, and a trailing slash.
     *
     * @param string $url - the address, or an empty string, which never matches.
     *
     * @return bool
     */
    public function postsTo(string $url) : bool
    {
        return ($url !== '' && $this->m_url === WebhookBasicAuth::removeCredentials($url));
    }


    /**
     * Check whether it authenticates with a secret.
     *
     * @param string $secret - the secret. An empty one never matches.
     *
     * @return bool
     */
    public function hasSecret(string $secret) : bool
    {
        return ($secret !== '' && hash_equals($secret, $this->m_secret));
    }


    # Accessors
    public function getUrl() : string { return $this->m_url; }
    public function getEvents() : array { return $this->m_events; }
}
