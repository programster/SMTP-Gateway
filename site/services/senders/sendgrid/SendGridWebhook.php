<?php

/*
 * One of the event webhooks set up in SendGrid ("Mail Settings" → "Event Webhooks"), as its API describes it.
 */

declare(strict_types = 1);


final readonly class SendGridWebhook
{
    // The events this service records, which the webhook should send.
    private const array WANTED_EVENTS = ['processed', 'delivered', 'deferred', 'bounce', 'dropped'];

    private string $m_url;
    private bool $m_isEnabled;
    private string $m_publicKey;
    private array $m_missingEvents;


    /**
     * @param string $url - where it sends the events.
     *
     * @param bool $isEnabled - whether it's switched on.
     *
     * @param string $publicKey - the public key its signatures can be checked with, or an empty string if it
     * doesn't sign them.
     *
     * @param list<string> $missingEvents - which of the events this service records it doesn't send, e.g.
     * ["bounce"].
     */
    public function __construct(string $url, bool $isEnabled, string $publicKey, array $missingEvents)
    {
        $this->m_url = $url;
        $this->m_isEnabled = $isEnabled;
        $this->m_publicKey = $publicKey;
        $this->m_missingEvents = $missingEvents;
    }


    /**
     * Create one from an entry in the API's list of event webhooks (GET /v3/user/webhooks/event/settings/all).
     *
     * @param array $entry - the entry, decoded from JSON.
     *
     * @return SendGridWebhook
     */
    public static function createFromApi(array $entry) : SendGridWebhook
    {
        $missingEvents = [];

        foreach (self::WANTED_EVENTS as $event)
        {
            if (($entry[$event] ?? false) !== true)
            {
                $missingEvents[] = $event;
            }
        }

        return new SendGridWebhook(
            (string)($entry['url'] ?? ''),
            ($entry['enabled'] ?? false) === true,
            (string)($entry['public_key'] ?? ''),
            $missingEvents
        );
    }


    /**
     * Check whether it sends its events to a URL (ignoring a trailing slash, and the case of the scheme and host).
     *
     * @param string $url - the URL, e.g. "https://smtp.example.org/webhooks/sendgrid".
     *
     * @return bool
     */
    public function sendsTo(string $url) : bool
    {
        return (self::normaliseUrl($this->m_url) === self::normaliseUrl($url));
    }


    /**
     * Check whether its signatures can be checked with a public key.
     *
     * @param string $publicKey - the public key (base64, as SendGrid shows it).
     *
     * @return bool
     */
    public function isSignedWith(string $publicKey) : bool
    {
        $ours = preg_replace('/\s+/', '', $publicKey);
        $theirs = preg_replace('/\s+/', '', $this->m_publicKey);
        return ($theirs !== '' && $theirs === $ours);
    }


    private static function normaliseUrl(string $url) : string
    {
        $url = rtrim(trim($url), '/');
        $schemeAndHost = (preg_match('#^[a-z]+://[^/]+#i', $url, $matches) === 1) ? $matches[0] : '';
        return strtolower($schemeAndHost) . substr($url, strlen($schemeAndHost));
    }


    # Accessors
    public function getUrl() : string { return $this->m_url; }
    public function isEnabled() : bool { return $this->m_isEnabled; }
    public function getPublicKey() : string { return $this->m_publicKey; }
    public function getMissingEvents() : array { return $this->m_missingEvents; }
}
