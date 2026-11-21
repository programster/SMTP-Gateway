<?php

/*
 * The things done to an account that can't simply be undone, or that weaken its security, so need the password of
 * whoever does them, in a dialog that says what will happen (see ViewAccountActionDialog).
 */

declare(strict_types = 1);


enum AccountAction : string
{
    // Block it, and remove its email address and sign-in details, keeping only its ID.
    case ARCHIVE = 'archive';

    // Delete an archived account for good.
    case DELETE = 'delete';

    // Let its next password login, within the hour, replace its second factors (see MfaGraceService).
    case MFA_GRACE = 'mfa-grace';


    /**
     * Get the path its form posts to, for an account.
     * @param string $userId - the account's ID.
     * @return string - e.g. "/admin/users/<ID>/archive".
     */
    public function getPath(string $userId) : string
    {
        return '/admin/users/' . rawurlencode($userId) . '/' . $this->value;
    }


    public function getDialogId() : string
    {
        return "{$this->value}-account-dialog";
    }


    public function getButtonLabel() : string
    {
        return match ($this) {
            AccountAction::ARCHIVE => 'Archive user',
            AccountAction::DELETE => 'Delete user for good',
            AccountAction::MFA_GRACE => 'Give a 1-hour grace period',
        };
    }
}
