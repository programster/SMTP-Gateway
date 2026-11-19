<?php

/*
 * Why something can't be done to an account in the state it's in now (see AccountStateException). The API responds
 * to each with a 409 Conflict, and its own error code.
 */

declare(strict_types = 1);


enum AccountStateProblem
{
    // Deleting an account that isn't archived: it has to be archived first.
    case NOT_ARCHIVED;

    // Doing something (other than restoring or deleting) to an account that's archived.
    case ARCHIVED;

    // Blocking or archiving the last account that can log in, which would lock everyone out.
    case LAST_ACTIVE_ACCOUNT;


    public function getErrorCode() : ErrorCodeEnum
    {
        return match ($this) {
            AccountStateProblem::NOT_ARCHIVED => ErrorCodeEnum::ACCOUNT_NOT_ARCHIVED,
            AccountStateProblem::ARCHIVED => ErrorCodeEnum::ACCOUNT_ARCHIVED,
            AccountStateProblem::LAST_ACTIVE_ACCOUNT => ErrorCodeEnum::LAST_ACTIVE_ACCOUNT,
        };
    }
}
