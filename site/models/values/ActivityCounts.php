<?php

/*
 * How much email has been coming through (for one email token, or for everything), and what has happened to it.
 */

declare(strict_types = 1);


readonly class ActivityCounts
{
    private int $m_lastMinute;
    private int $m_lastHour;
    private int $m_last24Hours;
    private int $m_last7Days;
    private int $m_queuedNow;
    private int $m_failedLast24Hours;
    private int $m_rejectedLastHour;
    private int $m_rejectedLast24Hours;
    private int $m_rejectedLast7Days;
    private int $m_deliveredLast7Days;
    private int $m_bouncedLast7Days;
    private int $m_openedLast7Days;
    private int $m_clickedLast7Days;
    private int $m_spamReportsLast7Days;
    private int $m_rejectedByServiceLast24Hours;
    private int $m_refusedWhileWaitingLast24Hours;
    private int $m_failedAfterQueueingLast24Hours;
    private int $m_retryingNow;


    /**
     * @param array $row - the counts, keyed by the names of this class's accessors without "get" (snake_case),
     * e.g. "last_minute", as read from the database.
     */
    public function __construct(array $row)
    {
        $this->m_lastMinute = (int)$row['last_minute'];
        $this->m_lastHour = (int)$row['last_hour'];
        $this->m_last24Hours = (int)$row['last_24_hours'];
        $this->m_last7Days = (int)$row['last_7_days'];
        $this->m_queuedNow = (int)$row['queued_now'];
        $this->m_failedLast24Hours = (int)$row['failed_last_24_hours'];
        $this->m_rejectedLastHour = (int)$row['rejected_last_hour'];
        $this->m_rejectedLast24Hours = (int)$row['rejected_last_24_hours'];
        $this->m_rejectedLast7Days = (int)$row['rejected_last_7_days'];
        $this->m_deliveredLast7Days = (int)$row['delivered_last_7_days'];
        $this->m_bouncedLast7Days = (int)$row['bounced_last_7_days'];
        $this->m_openedLast7Days = (int)$row['opened_last_7_days'];
        $this->m_clickedLast7Days = (int)$row['clicked_last_7_days'];
        $this->m_spamReportsLast7Days = (int)$row['spam_reports_last_7_days'];
        $this->m_rejectedByServiceLast24Hours = (int)$row['rejected_by_service_last_24_hours'];
        $this->m_refusedWhileWaitingLast24Hours = (int)$row['refused_while_waiting_last_24_hours'];
        $this->m_failedAfterQueueingLast24Hours = (int)$row['failed_after_queueing_last_24_hours'];
        $this->m_retryingNow = (int)$row['retrying_now'];
    }


    /**
     * Get the counts in the form the API returns them.
     *
     * @return array
     */
    public function toArray() : array
    {
        return [
            'acceptedLastMinute' => $this->m_lastMinute,
            'acceptedLastHour' => $this->m_lastHour,
            'acceptedLast24Hours' => $this->m_last24Hours,
            'acceptedLast7Days' => $this->m_last7Days,
            'queuedNow' => $this->m_queuedNow,
            'failedLast24Hours' => $this->m_failedLast24Hours,
            'rejectedLastHour' => $this->m_rejectedLastHour,
            'rejectedLast24Hours' => $this->m_rejectedLast24Hours,
            'rejectedLast7Days' => $this->m_rejectedLast7Days,
            'deliveredLast7Days' => $this->m_deliveredLast7Days,
            'bouncedLast7Days' => $this->m_bouncedLast7Days,
            'openedLast7Days' => $this->m_openedLast7Days,
            'clickedLast7Days' => $this->m_clickedLast7Days,
            'spamReportsLast7Days' => $this->m_spamReportsLast7Days,
            'rejectedByServiceLast24Hours' => $this->m_rejectedByServiceLast24Hours,
            'refusedWhileWaitingLast24Hours' => $this->m_refusedWhileWaitingLast24Hours,
            'failedAfterQueueingLast24Hours' => $this->m_failedAfterQueueingLast24Hours,
            'retryingNow' => $this->m_retryingNow,
        ];
    }


    # Accessors
    public function getLastMinute() : int { return $this->m_lastMinute; }
    public function getLastHour() : int { return $this->m_lastHour; }
    public function getLast24Hours() : int { return $this->m_last24Hours; }
    public function getLast7Days() : int { return $this->m_last7Days; }
    public function getQueuedNow() : int { return $this->m_queuedNow; }
    public function getFailedLast24Hours() : int { return $this->m_failedLast24Hours; }
    public function getRejectedLastHour() : int { return $this->m_rejectedLastHour; }
    public function getRejectedLast24Hours() : int { return $this->m_rejectedLast24Hours; }
    public function getRejectedLast7Days() : int { return $this->m_rejectedLast7Days; }
    public function getDeliveredLast7Days() : int { return $this->m_deliveredLast7Days; }
    public function getBouncedLast7Days() : int { return $this->m_bouncedLast7Days; }
    public function getOpenedLast7Days() : int { return $this->m_openedLast7Days; }
    public function getClickedLast7Days() : int { return $this->m_clickedLast7Days; }
    public function getSpamReportsLast7Days() : int { return $this->m_spamReportsLast7Days; }
    public function getRejectedByServiceLast24Hours() : int { return $this->m_rejectedByServiceLast24Hours; }
    public function getRefusedWhileWaitingLast24Hours() : int { return $this->m_refusedWhileWaitingLast24Hours; }
    public function getFailedAfterQueueingLast24Hours() : int { return $this->m_failedAfterQueueingLast24Hours; }
    public function getRetryingNow() : int { return $this->m_retryingNow; }
}
