<?php

/*
 * What the provider pages show about how the providers are being used: their recent attempts, which are being skipped,
 * and the email tokens restricted to particular providers.
 */

declare(strict_types = 1);


final readonly class ProviderActivity
{
    /** @var array<string, array<string, int>> */
    private array $m_recentCounts;

    /** @var array<string, array{unavailable_until: int, last_error: string}> */
    private array $m_unavailable;

    private ProviderTokenLinkCollection $m_tokenLinks;


    /**
     * @param array<string, array<string, int>> $recentCounts - each provider's attempts (see ProviderStats).
     *
     * @param array<string, array{unavailable_until: int, last_error: string}> $unavailable - the providers being
     * skipped, by name (see ProviderStatusRepository).
     *
     * @param ProviderTokenLinkCollection $tokenLinks - the email tokens restricted to particular providers.
     */
    public function __construct(array $recentCounts, array $unavailable, ProviderTokenLinkCollection $tokenLinks)
    {
        $this->m_recentCounts = $recentCounts;
        $this->m_unavailable = $unavailable;
        $this->m_tokenLinks = $tokenLinks;
    }


    # Accessors
    public function getRecentCounts() : array { return $this->m_recentCounts; }
    public function getUnavailable() : array { return $this->m_unavailable; }
    public function getTokenLinks() : ProviderTokenLinkCollection { return $this->m_tokenLinks; }
}
