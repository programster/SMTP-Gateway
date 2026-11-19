<?php

/*
 * An email built from a request to the send endpoint: its sender, every recipient (To, Cc and Bcc), and the email.
 */

declare(strict_types = 1);


readonly class ApiEmail
{
    private EmailAddress $m_sender;
    private EmailAddressCollection $m_recipients;
    private RawEmail $m_rawEmail;


    public function __construct(EmailAddress $sender, EmailAddressCollection $recipients, RawEmail $rawEmail)
    {
        $this->m_sender = $sender;
        $this->m_recipients = clone $recipients;
        $this->m_rawEmail = $rawEmail;
    }


    # Accessors
    public function getSender() : EmailAddress { return $this->m_sender; }
    public function getRecipients() : EmailAddressCollection { return clone $this->m_recipients; }
    public function getRawEmail() : RawEmail { return $this->m_rawEmail; }
}
