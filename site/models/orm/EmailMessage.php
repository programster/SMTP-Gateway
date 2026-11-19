<?php

/*
 * An email accepted for sending, and what has happened to it.
 */

declare(strict_types = 1);

use Programster\PgsqlObjects\TableInterface;


class EmailMessage extends \Programster\PgsqlObjects\AbstractRecord
{
    private ?string $m_emailTokenId;
    private string $m_source;
    private string $m_clientIp;
    private string $m_envelopeSender;
    private string $m_recipients;
    private int $m_recipientCount;
    private string $m_subject;
    private int $m_sizeBytes;
    private ?string $m_rawMessageBase64;
    private string $m_status;
    private bool $m_wasQueued;
    private int $m_attempts;
    private string $m_nextAttemptAt;
    private ?string $m_dispatchedAt;
    private ?string $m_sentAt;
    private ?string $m_provider;
    private ?string $m_providerMessageId;
    private ?string $m_lastError;
    private string $m_createdAt;
    private string $m_triedProviders;
    private int $m_retryRound;
    private ?string $m_attachmentsRemovedAt;
    private int $m_removedAttachmentCount;
    private int $m_priority;


    public function getTableHandler() : TableInterface
    {
        return EmailMessageTable::getInstance();
    }


    protected function getAccessorFunctions() : array
    {
        return [
            'email_token_id' => function () : ?string { return $this->m_emailTokenId; },
            'source' => function () : string { return $this->m_source; },
            'client_ip' => function () : string { return $this->m_clientIp; },
            'envelope_sender' => function () : string { return $this->m_envelopeSender; },
            'recipients' => function () : string { return $this->m_recipients; },
            'recipient_count' => function () : int { return $this->m_recipientCount; },
            'subject' => function () : string { return $this->m_subject; },
            'size_bytes' => function () : int { return $this->m_sizeBytes; },
            'raw_message_base64' => function () : ?string { return $this->m_rawMessageBase64; },
            'status' => function () : string { return $this->m_status; },
            'was_queued' => function () : bool { return $this->m_wasQueued; },
            'attempts' => function () : int { return $this->m_attempts; },
            'next_attempt_at' => function () : string { return $this->m_nextAttemptAt; },
            'dispatched_at' => function () : ?string { return $this->m_dispatchedAt; },
            'sent_at' => function () : ?string { return $this->m_sentAt; },
            'provider' => function () : ?string { return $this->m_provider; },
            'provider_message_id' => function () : ?string { return $this->m_providerMessageId; },
            'last_error' => function () : ?string { return $this->m_lastError; },
            'created_at' => function () : string { return $this->m_createdAt; },
            'tried_providers' => function () : string { return $this->m_triedProviders; },
            'retry_round' => function () : int { return $this->m_retryRound; },
            'attachments_removed_at' => function () : ?string { return $this->m_attachmentsRemovedAt; },
            'removed_attachment_count' => function () : int { return $this->m_removedAttachmentCount; },
            'priority' => function () : int { return $this->m_priority; },
        ];
    }


    protected function getSetFunctions() : array
    {
        return [
            'email_token_id' => function (?string $value) : void { $this->m_emailTokenId = $value; },
            'source' => function (string $value) : void { $this->m_source = $value; },
            'client_ip' => function (string $value) : void { $this->m_clientIp = $value; },
            'envelope_sender' => function (string $value) : void { $this->m_envelopeSender = $value; },
            'recipients' => function (string $value) : void { $this->m_recipients = $value; },
            'recipient_count' => function (int $value) : void { $this->m_recipientCount = $value; },
            'subject' => function (string $value) : void { $this->m_subject = $value; },
            'size_bytes' => function (int $value) : void { $this->m_sizeBytes = $value; },
            'raw_message_base64' => function (?string $value) : void { $this->m_rawMessageBase64 = $value; },
            'status' => function (string $value) : void { $this->m_status = $value; },
            'was_queued' => function (bool $value) : void { $this->m_wasQueued = $value; },
            'attempts' => function (int $value) : void { $this->m_attempts = $value; },
            'next_attempt_at' => function (string $value) : void { $this->m_nextAttemptAt = $value; },
            'dispatched_at' => function (?string $value) : void { $this->m_dispatchedAt = $value; },
            'sent_at' => function (?string $value) : void { $this->m_sentAt = $value; },
            'provider' => function (?string $value) : void { $this->m_provider = $value; },
            'provider_message_id' => function (?string $value) : void { $this->m_providerMessageId = $value; },
            'last_error' => function (?string $value) : void { $this->m_lastError = $value; },
            'created_at' => function (string $value) : void { $this->m_createdAt = $value; },
            'tried_providers' => function (string $value) : void { $this->m_triedProviders = $value; },
            'retry_round' => function (int $value) : void { $this->m_retryRound = $value; },
            'attachments_removed_at' => function (?string $value) : void { $this->m_attachmentsRemovedAt = $value; },
            'removed_attachment_count' => function (int $value) : void { $this->m_removedAttachmentCount = $value; },
            'priority' => function (int $value) : void { $this->m_priority = $value; },
        ];
    }


    /**
     * Whether the service sent this itself (e.g. a password reset link), rather than with an email token.
     */
    public function isFromService() : bool
    {
        return $this->m_emailTokenId === null;
    }


    # Accessors
    public function getEmailTokenId() : ?string { return $this->m_emailTokenId; }
    public function getSource() : string { return $this->m_source; }
    public function getClientIp() : string { return $this->m_clientIp; }
    public function getEnvelopeSender() : string { return $this->m_envelopeSender; }
    public function getRecipients() : string { return $this->m_recipients; }
    public function getRecipientCount() : int { return $this->m_recipientCount; }
    public function getSubject() : string { return $this->m_subject; }
    public function getSizeBytes() : int { return $this->m_sizeBytes; }
    public function getRawMessageBase64() : ?string { return $this->m_rawMessageBase64; }
    public function getStatus() : string { return $this->m_status; }
    public function wasQueued() : bool { return $this->m_wasQueued; }
    public function getAttempts() : int { return $this->m_attempts; }
    public function getNextAttemptAt() : string { return $this->m_nextAttemptAt; }
    public function getDispatchedAt() : ?string { return $this->m_dispatchedAt; }
    public function getSentAt() : ?string { return $this->m_sentAt; }
    public function getProvider() : ?string { return $this->m_provider; }
    public function getProviderMessageId() : ?string { return $this->m_providerMessageId; }
    public function getLastError() : ?string { return $this->m_lastError; }
    public function getCreatedAt() : string { return $this->m_createdAt; }
    public function getTriedProviders() : string { return $this->m_triedProviders; }
    public function getRetryRound() : int { return $this->m_retryRound; }
    public function getAttachmentsRemovedAt() : ?string { return $this->m_attachmentsRemovedAt; }
    public function getRemovedAttachmentCount() : int { return $this->m_removedAttachmentCount; }
    public function getPriority() : int { return $this->m_priority; }

}
