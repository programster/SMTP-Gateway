<?php

/*
 * Something a provider reported happening to an email, e.g. it was delivered or bounced.
 */

declare(strict_types = 1);

use Programster\PgsqlObjects\TableInterface;


class EmailEvent extends \Programster\PgsqlObjects\AbstractRecord
{
    private ?string $m_emailMessageId;
    private string $m_provider;
    private ?string $m_providerEventId;
    private string $m_eventType;
    private ?string $m_recipient;
    private ?string $m_detail;
    private int $m_occurredAt;
    private int $m_createdAt;


    public function getTableHandler() : TableInterface
    {
        return EmailEventTable::getInstance();
    }


    protected function getAccessorFunctions() : array
    {
        return [
            'email_message_id' => function () : ?string { return $this->m_emailMessageId; },
            'provider' => function () : string { return $this->m_provider; },
            'provider_event_id' => function () : ?string { return $this->m_providerEventId; },
            'event_type' => function () : string { return $this->m_eventType; },
            'recipient' => function () : ?string { return $this->m_recipient; },
            'detail' => function () : ?string { return $this->m_detail; },
            'occurred_at' => function () : int { return $this->m_occurredAt; },
            'created_at' => function () : int { return $this->m_createdAt; },
        ];
    }


    protected function getSetFunctions() : array
    {
        return [
            'email_message_id' => function (?string $value) : void { $this->m_emailMessageId = $value; },
            'provider' => function (string $value) : void { $this->m_provider = $value; },
            'provider_event_id' => function (?string $value) : void { $this->m_providerEventId = $value; },
            'event_type' => function (string $value) : void { $this->m_eventType = $value; },
            'recipient' => function (?string $value) : void { $this->m_recipient = $value; },
            'detail' => function (?string $value) : void { $this->m_detail = $value; },
            'occurred_at' => function (int $value) : void { $this->m_occurredAt = $value; },
            'created_at' => function (int $value) : void { $this->m_createdAt = $value; },
        ];
    }


    # Accessors
    public function getEmailMessageId() : ?string { return $this->m_emailMessageId; }
    public function getProvider() : string { return $this->m_provider; }
    public function getProviderEventId() : ?string { return $this->m_providerEventId; }
    public function getEventType() : string { return $this->m_eventType; }
    public function getRecipient() : ?string { return $this->m_recipient; }
    public function getDetail() : ?string { return $this->m_detail; }
    public function getOccurredAt() : int { return $this->m_occurredAt; }
    public function getCreatedAt() : int { return $this->m_createdAt; }
}
