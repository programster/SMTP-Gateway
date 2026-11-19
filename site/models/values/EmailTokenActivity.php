<?php

/*
 * An email token, with how much email has been sent with it.
 */

declare(strict_types = 1);


readonly class EmailTokenActivity
{
    private EmailToken $m_emailToken;
    private ActivityCounts $m_counts;


    public function __construct(EmailToken $emailToken, ActivityCounts $counts)
    {
        $this->m_emailToken = $emailToken;
        $this->m_counts = $counts;
    }


    # Accessors
    public function getEmailToken() : EmailToken { return $this->m_emailToken; }
    public function getCounts() : ActivityCounts { return $this->m_counts; }
}
