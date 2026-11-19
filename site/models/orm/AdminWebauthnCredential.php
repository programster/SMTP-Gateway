<?php

/*
 * A security key (e.g. a YubiKey) registered with WebAuthn as a second factor for an admin user.
 */

declare(strict_types = 1);

use Programster\PgsqlObjects\TableInterface;


class AdminWebauthnCredential extends \Programster\PgsqlObjects\AbstractRecord
{
    private string $m_adminUserId;
    private string $m_name;
    private string $m_credentialId;
    private string $m_publicKeyPem;
    private int $m_signatureCounter;
    private string $m_createdAt;
    private ?string $m_lastUsedAt;


    public function getTableHandler() : TableInterface
    {
        return AdminWebauthnCredentialTable::getInstance();
    }


    protected function getAccessorFunctions() : array
    {
        return [
            'admin_user_id' => function () : string { return $this->m_adminUserId; },
            'name' => function () : string { return $this->m_name; },
            'credential_id' => function () : string { return $this->m_credentialId; },
            'public_key_pem' => function () : string { return $this->m_publicKeyPem; },
            'signature_counter' => function () : int { return $this->m_signatureCounter; },
            'created_at' => function () : string { return $this->m_createdAt; },
            'last_used_at' => function () : ?string { return $this->m_lastUsedAt; },
        ];
    }


    protected function getSetFunctions() : array
    {
        return [
            'admin_user_id' => function (string $value) : void { $this->m_adminUserId = $value; },
            'name' => function (string $value) : void { $this->m_name = $value; },
            'credential_id' => function (string $value) : void { $this->m_credentialId = $value; },
            'public_key_pem' => function (string $value) : void { $this->m_publicKeyPem = $value; },
            'signature_counter' => function (int $value) : void { $this->m_signatureCounter = $value; },
            'created_at' => function (string $value) : void { $this->m_createdAt = $value; },
            'last_used_at' => function (?string $value) : void { $this->m_lastUsedAt = $value; },
        ];
    }


    # Accessors
    public function getAdminUserId() : string { return $this->m_adminUserId; }
    public function getName() : string { return $this->m_name; }
    public function getCredentialId() : string { return $this->m_credentialId; }
    public function getPublicKeyPem() : string { return $this->m_publicKeyPem; }
    public function getSignatureCounter() : int { return $this->m_signatureCounter; }
    public function getCreatedAt() : string { return $this->m_createdAt; }
    public function getLastUsedAt() : ?string { return $this->m_lastUsedAt; }
}
