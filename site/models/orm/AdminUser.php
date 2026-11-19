<?php

/*
 * An administrator who can log in to the web interface (unless they're blocked or archived). An archived account has
 * no email address, only its ID.
 */

declare(strict_types = 1);

use Programster\PgsqlObjects\TableInterface;


class AdminUser extends \Programster\PgsqlObjects\AbstractRecord
{
    private ?string $m_email;
    private string $m_passwordHash;
    private ?string $m_totpSecret;
    private ?int $m_totpLastUsedStep;
    private string $m_createdAt;
    private int $m_sessionVersion;
    private ?string $m_lastLoginAt;
    private ?string $m_lastLoginIp;
    private bool $m_mustChangePassword;
    private string $m_status;
    private ?string $m_statusChangedAt;


    public function getTableHandler() : TableInterface
    {
        return AdminUserTable::getInstance();
    }


    protected function getAccessorFunctions() : array
    {
        return [
            'email' => function () : ?string { return $this->m_email; },
            'password_hash' => function () : string { return $this->m_passwordHash; },
            'totp_secret' => function () : ?string { return $this->m_totpSecret; },
            'totp_last_used_step' => function () : ?int { return $this->m_totpLastUsedStep; },
            'created_at' => function () : string { return $this->m_createdAt; },
            'session_version' => function () : int { return $this->m_sessionVersion; },
            'last_login_at' => function () : ?string { return $this->m_lastLoginAt; },
            'last_login_ip' => function () : ?string { return $this->m_lastLoginIp; },
            'must_change_password' => function () : bool { return $this->m_mustChangePassword; },
            'status' => function () : string { return $this->m_status; },
            'status_changed_at' => function () : ?string { return $this->m_statusChangedAt; },
        ];
    }


    protected function getSetFunctions() : array
    {
        return [
            'email' => function (?string $value) : void { $this->m_email = $value; },
            'password_hash' => function (string $value) : void { $this->m_passwordHash = $value; },
            'totp_secret' => function (?string $value) : void { $this->m_totpSecret = $value; },
            'totp_last_used_step' => function (?int $value) : void { $this->m_totpLastUsedStep = $value; },
            'created_at' => function (string $value) : void { $this->m_createdAt = $value; },
            'session_version' => function (int $value) : void { $this->m_sessionVersion = $value; },
            'last_login_at' => function (?string $value) : void { $this->m_lastLoginAt = $value; },
            'last_login_ip' => function (?string $value) : void { $this->m_lastLoginIp = $value; },
            'must_change_password' => function (bool $value) : void { $this->m_mustChangePassword = $value; },
            'status' => function (string $value) : void { $this->m_status = $value; },
            'status_changed_at' => function (?string $value) : void { $this->m_statusChangedAt = $value; },
        ];
    }


    public function hasTotp() : bool
    {
        return $this->m_totpSecret !== null;
    }


    /**
     * Get the account's email address, which it logs in with.
     *
     * @return string
     *
     * @throws LogicException - if it's archived, so has none. Only live accounts' addresses are ever needed, so this
     * catches a mistake where it's made. Use describe() for an account that may be archived.
     */
    public function getEmail() : string
    {
        if ($this->m_email === null)
        {
            throw new LogicException("The account {$this->getId()} is archived, so has no email address.");
        }

        return $this->m_email;
    }


    /**
     * Describe the account for lists and links: its email address, or "Archived account" if it has none.
     * @return string
     */
    public function describe() : string
    {
        return $this->m_email ?? 'Archived account';
    }


    public function getStatus() : AccountStatus
    {
        return AccountStatus::from($this->m_status);
    }


    # Accessors
    public function getPasswordHash() : string { return $this->m_passwordHash; }
    public function getTotpSecret() : ?string { return $this->m_totpSecret; }
    public function getTotpLastUsedStep() : ?int { return $this->m_totpLastUsedStep; }
    public function getCreatedAt() : string { return $this->m_createdAt; }
    public function getSessionVersion() : int { return $this->m_sessionVersion; }
    public function getLastLoginAt() : ?string { return $this->m_lastLoginAt; }
    public function getLastLoginIp() : ?string { return $this->m_lastLoginIp; }
    public function mustChangePassword() : bool { return $this->m_mustChangePassword; }
    public function getStatusChangedAt() : ?string { return $this->m_statusChangedAt; }
}
