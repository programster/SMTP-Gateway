<?php

/*
 * Thrown when a request to send an email is turned away: bad credentials, an IP address the key isn't allowed to
 * be used from, an address its rules don't allow, and so on. The message is safe to show to the client.
 */

declare(strict_types = 1);


class RequestRejectedException extends Exception
{
    private readonly RejectionReason $m_reason;


    public function __construct(RejectionReason $reason, string $message)
    {
        parent::__construct($message);
        $this->m_reason = $reason;
    }


    # Accessors
    public function getReason() : RejectionReason { return $this->m_reason; }
}
