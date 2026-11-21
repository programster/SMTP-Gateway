<?php

/*
 * Something a provider reported happening to an email after it was handed over, e.g. it was delivered, bounced,
 * or opened.
 */

declare(strict_types = 1);


readonly class ProviderEvent
{
    private string $m_messageId;
    private string $m_providerEventId;
    private EmailEventType $m_type;
    private string $m_recipient;
    private DateTimeImmutable $m_occurredAt;
    private string $m_detail;
    private string $m_providerMessageId;


    /**
     * @param string $messageId - our ID for the email, if the provider passed it back, or an empty string.
     *
     * @param string $providerEventId - the provider's unique ID for the event, used to ignore repeats. May be an
     * empty string if the provider doesn't give one.
     *
     * @param EmailEventType $type - what happened.
     *
     * @param string $recipient - which recipient it happened for, or an empty string.
     *
     * @param DateTimeImmutable $occurredAt - when it happened.
     *
     * @param string $detail - any detail, such as a bounce reason or a clicked URL, or an empty string.
     *
     * @param string $providerMessageId - the provider's own ID for the email, for matching it when the provider can't
     * pass ours back, or an empty string.
     */
    public function __construct(
        string $messageId,
        string $providerEventId,
        EmailEventType $type,
        string $recipient,
        DateTimeImmutable $occurredAt,
        string $detail,
        string $providerMessageId = ''
    )
    {
        $this->m_providerMessageId = $providerMessageId;
        $this->m_messageId = $messageId;
        $this->m_providerEventId = $providerEventId;
        $this->m_type = $type;
        $this->m_recipient = $recipient;
        $this->m_occurredAt = $occurredAt;
        $this->m_detail = $detail;
    }


    # Accessors
    public function getMessageId() : string { return $this->m_messageId; }
    public function getProviderEventId() : string { return $this->m_providerEventId; }
    public function getType() : EmailEventType { return $this->m_type; }
    public function getRecipient() : string { return $this->m_recipient; }
    public function getOccurredAt() : DateTimeImmutable { return $this->m_occurredAt; }
    public function getDetail() : string { return $this->m_detail; }
    public function getProviderMessageId() : string { return $this->m_providerMessageId; }
}
