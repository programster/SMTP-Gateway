<?php

/*
 * A grace period for an account that has lost its second factor and backup codes. An administrator gives it one, and
 * the account's next password login within the hour gets in without a second factor. That login uses it up, and
 * removes the old authenticator app, security keys and backup codes, so it has to set up new ones. Changing the
 * password, blocking and archiving all cancel it, so a password reset link alone can never get past the second factor.
 */

declare(strict_types = 1);

use Programster\PgsqlObjects\Exceptions\ExceptionNoSuchIdException;


final class MfaGraceService
{
    /**
     * Give an account a grace period of one hour.
     *
     * @param AdminUser $user - the account.
     *
     * @return void
     *
     * @throws InvalidInputException - if it isn't active, or has no second factor to get past.
     */
    public static function grant(AdminUser $user) : void
    {
        if ($user->getStatus() !== AccountStatus::ACTIVE)
        {
            throw new InvalidInputException('Only an active account can be given a grace period. Unblock it first.');
        }

        if (AdminUserRepository::hasSecondFactor($user) === false)
        {
            throw new InvalidInputException('This account has no second factor, so it doesn\'t need a grace period.');
        }

        $id = ServiceFactory::getDb()->escapeValue($user->getId());
        ServiceFactory::getDb()->query(
            "UPDATE \"admin_user\" SET \"mfa_grace_until\" = unix_time() + 3600 WHERE \"id\" = {$id}"
        );
    }


    /**
     * Cancel an account's grace period, if it has one.
     *
     * @param AdminUser $user - the account.
     *
     * @return void
     *
     * @throws ExceptionNoSuchIdException - if the account no longer exists.
     */
    public static function cancel(AdminUser $user) : void
    {
        AdminUserTable::getInstance()->updateById($user->getId(), ['mfa_grace_until' => null]);
    }


    /**
     * Use up an account's grace period, if it has one that hasn't expired, removing its second factors and backup
     * codes and logging out its other sessions. It's done in one step, so a grace period can only ever be used once.
     * Only call this once the account's password has been checked.
     *
     * @param AdminUser $user - the account.
     *
     * @return bool - whether it had a grace period to use.
     */
    public static function redeem(AdminUser $user) : bool
    {
        $id = ServiceFactory::getDb()->escapeValue($user->getId());

        // One statement, so it's all or nothing, and the second factors are only removed if a grace period was used.
        $rows = pg_fetch_all(ServiceFactory::getDb()->query(
            'WITH "redeemed" AS (UPDATE "admin_user" SET "mfa_grace_until" = NULL, "totp_secret" = NULL,'
            . ' "totp_last_used_step" = NULL, "session_version" = "session_version" + 1'
            . " WHERE \"id\" = {$id} AND \"status\" = 'active' AND \"mfa_grace_until\" > unix_time() RETURNING \"id\"),"
            . ' "removed_keys" AS (DELETE FROM "admin_webauthn_credential"'
            . ' WHERE "admin_user_id" IN (SELECT "id" FROM "redeemed")),'
            . ' "removed_codes" AS (DELETE FROM "admin_backup_code"'
            . ' WHERE "admin_user_id" IN (SELECT "id" FROM "redeemed"))'
            . ' SELECT "id" FROM "redeemed"'
        ));

        return count($rows) > 0;
    }
}
