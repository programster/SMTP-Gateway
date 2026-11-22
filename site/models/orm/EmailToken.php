<?php

/*
 * A token that another service sends email with. Only the hash of its secret is stored.
 */

declare(strict_types = 1);

use Programster\PgsqlObjects\TableInterface;


class EmailToken extends \Programster\PgsqlObjects\AbstractRecord
{
    private string $m_name;
    private string $m_secretHash;
    private int $m_createdAt;
    private ?int $m_lastUsedAt;
    private ?string $m_lastUsedIp;
    private ?int $m_revokedAt;
    private int $m_priority;
    private bool $m_restrictsProviders;


    public function getTableHandler() : TableInterface
    {
        return EmailTokenTable::getInstance();
    }


    protected function getAccessorFunctions() : array
    {
        return [
            'name' => function () : string { return $this->m_name; },
            'secret_hash' => function () : string { return $this->m_secretHash; },
            'created_at' => function () : int { return $this->m_createdAt; },
            'last_used_at' => function () : ?int { return $this->m_lastUsedAt; },
            'last_used_ip' => function () : ?string { return $this->m_lastUsedIp; },
            'revoked_at' => function () : ?int { return $this->m_revokedAt; },
            'priority' => function () : int { return $this->m_priority; },
            'restricts_providers' => function () : bool { return $this->m_restrictsProviders; },
        ];
    }


    protected function getSetFunctions() : array
    {
        return [
            'name' => function (string $value) : void { $this->m_name = $value; },
            'secret_hash' => function (string $value) : void { $this->m_secretHash = $value; },
            'created_at' => function (int $value) : void { $this->m_createdAt = $value; },
            'last_used_at' => function (?int $value) : void { $this->m_lastUsedAt = $value; },
            'last_used_ip' => function (?string $value) : void { $this->m_lastUsedIp = $value; },
            'revoked_at' => function (?int $value) : void { $this->m_revokedAt = $value; },
            'priority' => function (int $value) : void { $this->m_priority = $value; },
            'restricts_providers' => function (bool $value) : void { $this->m_restrictsProviders = $value; },
        ];
    }


    public function isRevoked() : bool
    {
        return $this->m_revokedAt !== null;
    }


    # Accessors
    public function getName() : string { return $this->m_name; }
    public function getSecretHash() : string { return $this->m_secretHash; }
    public function getCreatedAt() : int { return $this->m_createdAt; }
    public function getLastUsedAt() : ?int { return $this->m_lastUsedAt; }
    public function getLastUsedIp() : ?string { return $this->m_lastUsedIp; }
    public function getPriority() : int { return $this->m_priority; }
    public function getRevokedAt() : ?int { return $this->m_revokedAt; }
    public function restrictsProviders() : bool { return $this->m_restrictsProviders; }
}
