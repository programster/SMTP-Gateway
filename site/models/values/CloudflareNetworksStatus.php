<?php

/*
 * How many of Cloudflare's address ranges are stored, and when they were last fetched, for the Security settings.
 */

declare(strict_types = 1);


final readonly class CloudflareNetworksStatus
{
    private int $m_count;
    private int $m_fetchedAt;


    /**
     * @param int $count - how many ranges are stored.
     *
     * @param int $fetchedAt - when they were last fetched (a Unix timestamp), or 0 if they never have been.
     */
    public function __construct(int $count, int $fetchedAt)
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
    public function getFetchedAt() : int { return $this->m_fetchedAt; }
}
