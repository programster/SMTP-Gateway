<?php

/*
 * What a log entry's context names mean. The logs name accounts only by their ID, under "user_id" (the account it's
 * about) or a name ending in "_user_id" (e.g. "by_user_id", the account that did it), never by their email address,
 * so nothing in the logs has to change when an account is archived or deleted. The log entry page links each one to
 * the account, showing its email address if it still has one.
 */

declare(strict_types = 1);


final class LogContextKey
{
    /**
     * Whether a context name's value is an account's ID.
     * @param string $name - the name, e.g. "by_user_id".
     * @return bool
     */
    public static function isAccountId(string $name) : bool
    {
        return ($name === 'user_id' || str_ends_with($name, '_user_id'));
    }
}
