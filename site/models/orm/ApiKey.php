<?php

/*
 * An API key for the management API (/api/v1), used as a bearer token. Its ID is the start of the key (see Token),
 * and only the hash of its secret is stored.
 */

declare(strict_types = 1);

use Programster\PgsqlObjects\TableInterface;


class ApiKey extends \Programster\PgsqlObjects\AbstractRecord
{
    private string $m_name;
    private string $m_secretHash;
    private string $m_createdAt;
    private ?string $m_lastUsedAt;
    private ?string $m_lastUsedIp;
    private ?string $m_revokedAt;


    public function getTableHandler() : TableInterface
    {
        return ApiKeyTable::getInstance();
    }


    protected function getAccessorFunctions() : array
    {
        return [
            'name' => function () : string { return $this->m_name; },
            'secret_hash' => function () : string { return $this->m_secretHash; },
            'created_at' => function () : string { return $this->m_createdAt; },
            'last_used_at' => function () : ?string { return $this->m_lastUsedAt; },
            'last_used_ip' => function () : ?string { return $this->m_lastUsedIp; },
            'revoked_at' => function () : ?string { return $this->m_revokedAt; },
        ];
    }


    protected function getSetFunctions() : array
    {
        return [
            'name' => function (string $value) : void { $this->m_name = $value; },
            'secret_hash' => function (string $value) : void { $this->m_secretHash = $value; },
            'created_at' => function (string $value) : void { $this->m_createdAt = $value; },
            'last_used_at' => function (?string $value) : void { $this->m_lastUsedAt = $value; },
            'last_used_ip' => function (?string $value) : void { $this->m_lastUsedIp = $value; },
            'revoked_at' => function (?string $value) : void { $this->m_revokedAt = $value; },
        ];
    }


    public function isRevoked() : bool
    {
        return $this->m_revokedAt !== null;
    }


    # Accessors
    public function getName() : string { return $this->m_name; }
    public function getSecretHash() : string { return $this->m_secretHash; }
    public function getCreatedAt() : string { return $this->m_createdAt; }
    public function getLastUsedAt() : ?string { return $this->m_lastUsedAt; }
    public function getLastUsedIp() : ?string { return $this->m_lastUsedIp; }
    public function getRevokedAt() : ?string { return $this->m_revokedAt; }
}
