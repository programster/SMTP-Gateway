<?php

/*
 * A domain authenticated with a provider (e.g. SendGrid's "Sender Authentication", or a Mailgun sending domain), so
 * the provider DKIM-signs email as it: the domain, and whether the provider found the DNS records it asked for when
 * it last checked.
 */

declare(strict_types = 1);


final readonly class AuthenticatedDomain
{
    private string $m_domain;
    private bool $m_isValid;
    private array $m_invalidRecords;
    private int $m_lastCheckedAt;


    /**
     * @param string $domain - the domain, e.g. "example.org".
     *
     * @param bool $isValid - whether the provider found all its DNS records, so it can send as it.
     *
     * @param list<string> $invalidRecords - each record the provider didn't find, described, e.g.
     * "CNAME em123.example.org → u123.wl.sendgrid.net".
     *
     * @param int $lastCheckedAt - when the provider last checked them (a Unix timestamp), or 0 if that isn't known.
     */
    public function __construct(string $domain, bool $isValid, array $invalidRecords, int $lastCheckedAt)
    {
        $this->m_domain = strtolower(trim($domain));
        $this->m_isValid = $isValid;
        $this->m_invalidRecords = $invalidRecords;
        $this->m_lastCheckedAt = $lastCheckedAt;
    }


    /**
     * Check whether email from a domain is covered by this one: it's this domain, or a subdomain of it.
     *
     * @param string $domain - the sending domain.
     *
     * @return bool
     */
    public function covers(string $domain) : bool
    {
        $domain = strtolower($domain);
        return ($domain === $this->m_domain || str_ends_with($domain, '.' . $this->m_domain));
    }


    # Accessors
    public function getDomain() : string { return $this->m_domain; }
    public function isValid() : bool { return $this->m_isValid; }
    public function getInvalidRecords() : array { return $this->m_invalidRecords; }
    public function getLastCheckedAt() : int { return $this->m_lastCheckedAt; }
}
