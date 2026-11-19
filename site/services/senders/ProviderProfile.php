<?php

/*
 * What IdentityChecks needs to know about a provider to describe its senders in its own terms: what it's called,
 * where in its settings a domain is authenticated, what it does with email from a sender it hasn't verified, and
 * whether (and as what) it sends from an address that's only verified on its own.
 */

declare(strict_types = 1);


final readonly class ProviderProfile
{
    private string $m_label;
    private string $m_domainSettings;
    private UnverifiedSenderHandling $m_unverifiedHandling;
    private string $m_refusalMessage;
    private string $m_singleSenderSignsAs;


    /**
     * @param string $label - the provider's own name, e.g. "SendGrid".
     *
     * @param string $domainSettings - where in its web interface a domain is authenticated, e.g. "Settings → Sender
     * Authentication".
     *
     * @param UnverifiedSenderHandling $unverifiedHandling - what it does with email from a sender it hasn't verified.
     *
     * @param string $refusalMessage - the error it gives when it refuses one, e.g. "The from address does not match
     * a verified Sender Identity", or an empty string if it doesn't refuse them (or its error isn't known).
     *
     * @param string $singleSenderSignsAs - the domain it DKIM-signs as when only the address is verified, e.g.
     * "sendgrid.net", or an empty string if it can't verify single addresses at all.
     */
    public function __construct(
        string $label,
        string $domainSettings,
        UnverifiedSenderHandling $unverifiedHandling,
        string $refusalMessage,
        string $singleSenderSignsAs
    )
    {
        $this->m_label = $label;
        $this->m_domainSettings = $domainSettings;
        $this->m_unverifiedHandling = $unverifiedHandling;
        $this->m_refusalMessage = $refusalMessage;
        $this->m_singleSenderSignsAs = $singleSenderSignsAs;
    }


    /**
     * Whether it can verify a single address on its own, without its domain.
     *
     * @return bool
     */
    public function hasSingleSenders() : bool
    {
        return $this->m_singleSenderSignsAs !== '';
    }


    # Accessors
    public function getLabel() : string { return $this->m_label; }
    public function getDomainSettings() : string { return $this->m_domainSettings; }
    public function getUnverifiedHandling() : UnverifiedSenderHandling { return $this->m_unverifiedHandling; }
    public function getRefusalMessage() : string { return $this->m_refusalMessage; }
    public function getSingleSenderSignsAs() : string { return $this->m_singleSenderSignsAs; }
}
