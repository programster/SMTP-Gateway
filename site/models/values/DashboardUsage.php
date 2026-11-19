<?php

/*
 * Who and what has been using the service, for the dashboard's widgets: the most recent logins, the API keys and
 * email tokens used most recently (each with when, and from where), and the email tokens that sent the most emails in
 * a period.
 */

declare(strict_types = 1);


final readonly class DashboardUsage
{
    private RecentUseCollection $m_logins;
    private RecentUseCollection $m_apiKeys;
    private RecentUseCollection $m_emailTokens;
    private EmailTokenUsageCollection $m_mostUsed;
    private UsagePeriod $m_period;
    private int $m_recordDays;


    /**
     * @param RecentUseCollection $logins - the most recent logins, most recent first.
     *
     * @param RecentUseCollection $apiKeys - the API keys used most recently, most recent first.
     *
     * @param RecentUseCollection $emailTokens - the email tokens used most recently, most recent first.
     *
     * @param EmailTokenUsageCollection $mostUsed - the email tokens that sent the most emails in the period.
     *
     * @param UsagePeriod $period - the period the most used are counted over.
     *
     * @param int $recordDays - how many days email records are kept, which limits what can be counted.
     */
    public function __construct(
        RecentUseCollection $logins,
        RecentUseCollection $apiKeys,
        RecentUseCollection $emailTokens,
        EmailTokenUsageCollection $mostUsed,
        UsagePeriod $period,
        int $recordDays
    )
    {
        $this->m_logins = $logins;
        $this->m_apiKeys = $apiKeys;
        $this->m_emailTokens = $emailTokens;
        $this->m_mostUsed = $mostUsed;
        $this->m_period = $period;
        $this->m_recordDays = $recordDays;
    }


    # Accessors
    public function getLogins() : RecentUseCollection { return $this->m_logins; }
    public function getApiKeys() : RecentUseCollection { return $this->m_apiKeys; }
    public function getEmailTokens() : RecentUseCollection { return $this->m_emailTokens; }
    public function getMostUsed() : EmailTokenUsageCollection { return $this->m_mostUsed; }
    public function getPeriod() : UsagePeriod { return $this->m_period; }
    public function getRecordDays() : int { return $this->m_recordDays; }
}
