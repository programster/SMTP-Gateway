<?php

/*
 * Whether an account can be used: active, blocked (it can't log in until it's unblocked), or archived (blocked, with
 * its email address and sign-in details removed, keeping only its ID, so the logs that name it still make sense).
 */

declare(strict_types = 1);


enum AccountStatus : string
{
    case ACTIVE = 'active';
    case BLOCKED = 'blocked';
    case ARCHIVED = 'archived';


    public function getLabel() : string
    {
        return match ($this) {
            AccountStatus::ACTIVE => 'Active',
            AccountStatus::BLOCKED => 'Blocked',
            AccountStatus::ARCHIVED => 'Archived',
        };
    }


    /**
     * Whether an account with this status can log in.
     * @return bool
     */
    public function canLogIn() : bool
    {
        return ($this === AccountStatus::ACTIVE);
    }
}
