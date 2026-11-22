<?php

/*
 * A request to send an email that was turned away.
 */

declare(strict_types = 1);

use Programster\PgsqlObjects\TableInterface;


class RejectedRequest extends \Programster\PgsqlObjects\AbstractRecord
{
    private ?string $m_emailTokenId;
    private string $m_source;
    private string $m_clientIp;
    private string $m_reason;
    private string $m_detail;
    private int $m_createdAt;


    public function getTableHandler() : TableInterface
    {
        return RejectedRequestTable::getInstance();
    }


    protected function getAccessorFunctions() : array
    {
        return [
            'email_token_id' => function () : ?string { return $this->m_emailTokenId; },
            'source' => function () : string { return $this->m_source; },
            'client_ip' => function () : string { return $this->m_clientIp; },
            'reason' => function () : string { return $this->m_reason; },
            'detail' => function () : string { return $this->m_detail; },
            'created_at' => function () : int { return $this->m_createdAt; },
        ];
    }


    protected function getSetFunctions() : array
    {
        return [
            'email_token_id' => function (?string $value) : void { $this->m_emailTokenId = $value; },
            'source' => function (string $value) : void { $this->m_source = $value; },
            'client_ip' => function (string $value) : void { $this->m_clientIp = $value; },
            'reason' => function (string $value) : void { $this->m_reason = $value; },
            'detail' => function (string $value) : void { $this->m_detail = $value; },
            'created_at' => function (int $value) : void { $this->m_createdAt = $value; },
        ];
    }


    # Accessors
    public function getEmailTokenId() : ?string { return $this->m_emailTokenId; }
    public function getSource() : string { return $this->m_source; }
    public function getClientIp() : string { return $this->m_clientIp; }
    public function getReason() : string { return $this->m_reason; }
    public function getDetail() : string { return $this->m_detail; }
    public function getCreatedAt() : int { return $this->m_createdAt; }
}
