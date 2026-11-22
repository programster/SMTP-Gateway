<?php

/*
 * How many of an account's backup codes are still unused, and when they were generated, for its account page.
 */

declare(strict_types = 1);


readonly class BackupCodeStatus
{
    // When this few are left, the account page (and logging in with one) suggests generating new ones.
    public const int RUNNING_LOW = 2;

    private int $m_numUnused;
    private int $m_generatedAt;


    /**
     * @param int $numUnused - how many are still unused.
     *
     * @param int $generatedAt - when they were generated (a Unix timestamp), or 0 if the account has none at all.
     */
    public function __construct(int $numUnused, int $generatedAt)
    {
        $this->m_numUnused = $numUnused;
        $this->m_generatedAt = $generatedAt;
    }


    public function hasCodes() : bool
    {
        return $this->m_generatedAt !== 0;
    }


    public function isRunningLow() : bool
    {
        return $this->m_numUnused <= self::RUNNING_LOW;
    }


    # Accessors
    public function getNumUnused() : int { return $this->m_numUnused; }
    public function getGeneratedAt() : int { return $this->m_generatedAt; }
}
