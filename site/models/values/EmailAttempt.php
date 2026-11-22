<?php

/*
 * One attempt at handing an email to a provider (a row of email_attempt).
 */

declare(strict_types = 1);


readonly class EmailAttempt
{
    private string $m_provider;
    private float $m_dispatchedAt;
    private ?float $m_finishedAt;
    private AttemptOutcome $m_outcome;
    private string $m_error;


    /**
     * @param string $provider - the provider's name.
     *
     * @param float $dispatchedAt - when it was handed over (a Unix timestamp).
     *
     * @param float|null $finishedAt - when the provider answered, or null while it's still being sent.
     *
     * @param AttemptOutcome $outcome - how it ended.
     *
     * @param string $error - why it failed, or an empty string if it didn't.
     */
    public function __construct(
        string $provider,
        float $dispatchedAt,
        ?float $finishedAt,
        AttemptOutcome $outcome,
        string $error
    )
    {
        $this->m_provider = $provider;
        $this->m_dispatchedAt = $dispatchedAt;
        $this->m_finishedAt = $finishedAt;
        $this->m_outcome = $outcome;
        $this->m_error = $error;
    }


    # Accessors
    public function getProvider() : string { return $this->m_provider; }
    public function getDispatchedAt() : float { return $this->m_dispatchedAt; }
    public function getFinishedAt() : ?float { return $this->m_finishedAt; }
    public function getOutcome() : AttemptOutcome { return $this->m_outcome; }
    public function getError() : string { return $this->m_error; }
}
