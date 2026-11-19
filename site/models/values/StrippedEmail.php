<?php

/*
 * An email with its attachments taken out (see AttachmentStripper), and how many there were.
 */

declare(strict_types = 1);


readonly class StrippedEmail
{
    private string $m_raw;
    private int $m_removedCount;


    public function __construct(string $raw, int $removedCount)
    {
        $this->m_raw = $raw;
        $this->m_removedCount = $removedCount;
    }


    # Accessors
    public function getRaw() : string { return $this->m_raw; }
    public function getRemovedCount() : int { return $this->m_removedCount; }
}
