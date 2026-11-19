<?php

/*
 * A report that new SMTP connections had to wait, as every one of the SMTP_MAX_CONNECTIONS connections the server
 * allows at once was in use. The server's parent process never connects to the database, so it hands this to the next
 * connection's child process, which logs it.
 */

declare(strict_types = 1);


final readonly class SmtpLimitReport
{
    private int $m_maxConnections;
    private int $m_timesReached;
    private float $m_longestWaitSeconds;


    /**
     * @param int $maxConnections - how many connections the server allows at once (SMTP_MAX_CONNECTIONS).
     *
     * @param int $timesReached - how many times since the last report new connections had to wait.
     *
     * @param float $longestWaitSeconds - the longest that every connection stayed in use while one was waiting.
     */
    public function __construct(int $maxConnections, int $timesReached, float $longestWaitSeconds)
    {
        $this->m_maxConnections = $maxConnections;
        $this->m_timesReached = $timesReached;
        $this->m_longestWaitSeconds = $longestWaitSeconds;
    }


    public function log() : void
    {
        $msg =
            'Every SMTP connection was in use, so new ones had to wait for one to finish. If this happens often,'
            . ' raise SMTP_MAX_CONNECTIONS in .env, if the server has the memory for it (see Settings > Server'
            . ' resources).';

        ServiceFactory::getLogger(LogCategory::EMAIL)->warning($msg, [
            'smtp_max_connections' => $this->m_maxConnections,
            'times_reached' => $this->m_timesReached,
            'longest_wait_seconds' => round($this->m_longestWaitSeconds, 1),
        ]);
    }
}
