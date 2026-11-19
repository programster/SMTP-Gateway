<?php

/*
 * How many of Cloudflare's address ranges are stored, and when they were last fetched, for the Security settings.
 */

declare(strict_types = 1);


final readonly class CloudflareNetworksStatus
{
    private int $m_count;
    private string $m_fetchedAt;


    /**
     * @param int $count - how many ranges are stored.
     *
     * @param string $fetchedAt - when they were last fetched, or an empty string, if they never have been.
     */
    public function __construct(int $count, string $fetchedAt)
    {
        $this->m_count = $count;
        $this->m_fetchedAt = $fetchedAt;
    }


    public function hasBeenFetched() : bool
    {
        return $this->m_count > 0;
    }


    # Accessors
    public function getCount() : int { return $this->m_count; }
    public function getFetchedAt() : string { return $this->m_fetchedAt; }
}
