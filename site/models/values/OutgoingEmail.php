<?php

/*
 * An email ready to be handed to a provider: who it's from and to (the SMTP envelope), and the email itself.
 */

declare(strict_types = 1);


readonly class OutgoingEmail
{
    private string $m_messageId;
    private EmailAddress $m_envelopeSender;
    private EmailAddressCollection $m_recipients;
    private RawEmail $m_rawEmail;


    /**
     * @param string $messageId - our ID for the email (the email_message row), which providers that support it
     * attach to the email, so their tracking events can be matched back to it.
     *
     * @param EmailAddress $envelopeSender - the envelope sender (MAIL FROM), which is where bounces go.
     *
     * @param EmailAddressCollection $recipients - who to deliver to (To, Cc and Bcc alike). At least one.
     *
     * @param RawEmail $rawEmail - the email itself.
     *
     * @throws InvalidInputException - if there are no recipients.
     */
    public function __construct(
        string $messageId,
        EmailAddress $envelopeSender,
        EmailAddressCollection $recipients,
        RawEmail $rawEmail
    )
    {
        if (count($recipients) === 0)
        {
            throw new InvalidInputException('An email must have at least one recipient.');
        }

        $this->m_messageId = $messageId;
        $this->m_envelopeSender = $envelopeSender;
        $this->m_recipients = clone $recipients;
        $this->m_rawEmail = $rawEmail;
    }


    # Accessors
    public function getMessageId() : string { return $this->m_messageId; }
    public function getEnvelopeSender() : EmailAddress { return $this->m_envelopeSender; }
    public function getRecipients() : EmailAddressCollection { return clone $this->m_recipients; }
    public function getRawEmail() : RawEmail { return $this->m_rawEmail; }
}
