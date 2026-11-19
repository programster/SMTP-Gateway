<?php

/*
 * How many emails were accepted in one hour, and how many of those had to wait in the queue.
 */

declare(strict_types = 1);


readonly class HourlyVolume
{
    private DateTimeImmutable $m_hourStart;
    private int $m_accepted;
    private int $m_queued;


    public function __construct(DateTimeImmutable $hourStart, int $accepted, int $queued)
    {
        $this->m_hourStart = $hourStart;
        $this->m_accepted = $accepted;
        $this->m_queued = $queued;
    }


    # Accessors
    public function getHourStart() : DateTimeImmutable { return $this->m_hourStart; }
    public function getAccepted() : int { return $this->m_accepted; }
    public function getQueued() : int { return $this->m_queued; }
}
