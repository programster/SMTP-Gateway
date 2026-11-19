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
    private string $m_generatedAt;


    /**
     * @param int $numUnused - how many are still unused.
     *
     * @param string $generatedAt - when they were generated, or an empty string if the account has none at all.
     */
    public function __construct(int $numUnused, string $generatedAt)
    {
        $this->m_numUnused = $numUnused;
        $this->m_generatedAt = $generatedAt;
    }


    public function hasCodes() : bool
    {
        return $this->m_generatedAt !== '';
    }


    public function isRunningLow() : bool
    {
        return $this->m_numUnused <= self::RUNNING_LOW;
    }


    # Accessors
    public function getNumUnused() : int { return $this->m_numUnused; }
    public function getGeneratedAt() : string { return $this->m_generatedAt; }
}
