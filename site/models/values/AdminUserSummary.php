<?php

/*
 * An account, with which second factors it has set up, and how many backup codes it has left, for listing accounts.
 */

declare(strict_types = 1);


readonly class AdminUserSummary
{
    private AdminUser $m_user;
    private int $m_securityKeyCount;
    private int $m_unusedBackupCodeCount;


    public function __construct(AdminUser $user, int $securityKeyCount, int $unusedBackupCodeCount)
    {
        $this->m_user = $user;
        $this->m_securityKeyCount = $securityKeyCount;
        $this->m_unusedBackupCodeCount = $unusedBackupCodeCount;
    }


    # Accessors
    public function getUser() : AdminUser { return $this->m_user; }
    public function getSecurityKeyCount() : int { return $this->m_securityKeyCount; }
    public function getUnusedBackupCodeCount() : int { return $this->m_unusedBackupCodeCount; }
}
