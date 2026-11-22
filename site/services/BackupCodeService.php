<?php

/*
 * Backup codes: single-use codes an account can give instead of its second factor when logging in, for when its
 * authenticator app or security key is lost. They're generated as a set of COUNT, shown once, and only a hash of each
 * is stored. Generating a new set replaces the old one, whose codes then stop working.
 */

declare(strict_types = 1);

use Programster\PgsqlLib\Query;
use Programster\PgsqlObjects\OrmTransaction;


final class BackupCodeService
{
    // How many are generated at a time.
    public const int COUNT = 6;


    /**
     * Generate a new set of codes for an account, replacing any it has (which stop working).
     *
     * @param AdminUser $user - the account.
     *
     * @return list<string> - the codes, as they're shown (e.g. "k7m2p-x9q4r"). This is the only time they're
     * available, as only their hashes are stored.
     */
    public static function replace(AdminUser $user) : array
    {
        $codes = [];

        while (count($codes) < self::COUNT)
        {
            $codes[BackupCode::generate()] = true;
        }

        $codes = array_keys($codes);
        $userId = $user->getId();
        $queries = [new Query('DELETE FROM "admin_backup_code" WHERE "admin_user_id" = $1', [$userId])];
        $columns = '"id", "admin_user_id", "code_hash"';
        $insert = 'INSERT INTO "admin_backup_code" (' . $columns . ') VALUES (gen_random_uuid(), $1, $2)';

        foreach ($codes as $code)
        {
            $queries[] = new Query($insert, [$userId, password_hash(BackupCode::normalise($code), PASSWORD_DEFAULT)]);
        }

        $transaction = new OrmTransaction(ServiceFactory::getDb(), $queries);
        $transaction->commit();
        return $codes;
    }


    /**
     * Check a backup code given when logging in, and if it's one of the account's unused codes, use it up, so it can't
     * be used again.
     *
     * @param AdminUser $user - the account.
     *
     * @param string $typed - the code, as typed (in any case, with or without the dash or spaces).
     *
     * @return bool - whether it was right (and is now used).
     */
    public static function redeem(AdminUser $user, string $typed) : bool
    {
        $code = BackupCode::normalise($typed);
        $matchingId = '';

        if (BackupCode::isValid($code))
        {
            $userId = DB::escape($user->getId());
            $query = "SELECT \"id\", \"code_hash\" FROM \"admin_backup_code\" WHERE \"admin_user_id\" = {$userId}";

            foreach (DB::fetchRows($query . ' AND "used_at" IS NULL') as $row)
            {
                if ($matchingId === '' && password_verify($code, $row['code_hash']))
                {
                    $matchingId = $row['id'];
                }
            }
        }

        $isUsed = false;

        if ($matchingId !== '')
        {
            // Only one request can use it, even if two arrive at once.
            $update = 'UPDATE "admin_backup_code" SET "used_at" = now() WHERE "id" = $1 AND "used_at" IS NULL';
            $isUsed = pg_num_rows(ServiceFactory::getDb()->query($update . ' RETURNING "id"', [$matchingId])) === 1;
        }

        return $isUsed;
    }


    /**
     * Load how many of an account's codes are unused, and when they were generated.
     * @param AdminUser $user - the account.
     * @return BackupCodeStatus
     */
    public static function loadStatus(AdminUser $user) : BackupCodeStatus
    {
        $userId = DB::escape($user->getId());

        $rows = DB::fetchRows(
            'SELECT count(*) FILTER (WHERE "used_at" IS NULL) AS "unused", min("created_at") AS "generated_at"'
            . " FROM \"admin_backup_code\" WHERE \"admin_user_id\" = {$userId}"
        );

        return new BackupCodeStatus((int)$rows[0]['unused'], (string)($rows[0]['generated_at'] ?? ''));
    }


    /**
     * Delete all of an account's codes, e.g. when its last second factor is removed, so none are left to work if it
     * sets one up again later (when it's given a new set instead).
     *
     * @param AdminUser $user - the account.
     *
     * @return void
     */
    public static function deleteAll(AdminUser $user) : void
    {
        ServiceFactory::getDb()->query('DELETE FROM "admin_backup_code" WHERE "admin_user_id" = $1', [$user->getId()]);
    }
}
