<?php

/*
 * A page of log entries, and how many entries match the filter in all, for the page links.
 */

declare(strict_types = 1);


final readonly class LogSearchResult
{
    private LogEntryCollection $m_entries;
    private int $m_totalCount;


    public function __construct(LogEntryCollection $entries, int $totalCount)
    {
        $this->m_entries = $entries;
        $this->m_totalCount = $totalCount;
    }


    public static function createEmpty() : LogSearchResult
    {
        return new LogSearchResult(new LogEntryCollection(), 0);
    }


    # Accessors
    public function getEntries() : LogEntryCollection { return $this->m_entries; }
    public function getTotalCount() : int { return $this->m_totalCount; }
}
