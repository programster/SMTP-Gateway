<?php

/*
 * What happened to an email straight after it was accepted: whether it was sent straight away or queued, and the
 * ID it can be looked up by.
 */

declare(strict_types = 1);


readonly class SubmissionResult
{
    private string $m_messageId;
    private SubmissionOutcome $m_outcome;


    public function __construct(string $messageId, SubmissionOutcome $outcome)
    {
        $this->m_messageId = $messageId;
        $this->m_outcome = $outcome;
    }


    # Accessors
    public function getMessageId() : string { return $this->m_messageId; }
    public function getOutcome() : SubmissionOutcome { return $this->m_outcome; }
}
