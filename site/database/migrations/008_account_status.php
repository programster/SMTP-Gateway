<?php

/*
 * Accounts can be blocked (they can't log in until they're unblocked) or archived (blocked, with their email address
 * and sign-in details removed, keeping only their ID), as well as deleted. An archived account has no email address,
 * and a live one always has one.
 *
 * The logs now name accounts only by their ID ("user_id", "by_user_id"), never their email address, so archiving or
 * deleting an account never has to change the logs. Existing log entries are converted where they can be: an "email",
 * "by" or "to" whose value is a current account's email address becomes that account's ID. Addresses that no longer
 * belong to an account (e.g. one an account has since changed from, or a deleted account's) are left as they are.
 */

use Programster\PgsqlLib\PgSqlConnection;


class AccountStatusMigration implements Programster\PgsqlMigrations\MigrationInterface
{
    /**
     * Run queries for upgrading the database version.
     *
     * @throws \Programster\PgsqlLib\Exceptions\ExceptionQueryError - if there is an issue with the query.
     * @throws \Programster\PgsqlLib\Exceptions\ExceptionConnectionError - if database is not actually connected.
     */
    public function up(\PgSql\Connection $connectionResource) : void
    {
        $db = new PgSqlConnection($connectionResource);

        $db->query(
            'ALTER TABLE "admin_user"'
            . " ADD COLUMN \"status\" varchar(20) NOT NULL DEFAULT 'active'"
            . " CHECK (\"status\" IN ('active', 'blocked', 'archived')),"
            . ' ADD COLUMN "status_changed_at" timestamptz NULL,'
            . ' ALTER COLUMN "email" DROP NOT NULL,'
            . ' ADD CONSTRAINT "admin_user_archived_has_no_email" CHECK (("status" = \'archived\') = ("email" IS NULL))'
        );

        $conversions = [['email', 'user_id'], ['to', 'user_id'], ['by', 'by_user_id']];

        foreach ($conversions as [$oldKey, $newKey])
        {
            $db->query(
                'UPDATE "log" SET "context" = ("log"."context" - $1) || jsonb_build_object($2::text, "u"."id"::text)'
                . ' FROM "admin_user" AS "u" WHERE "log"."context" ->> $1 = "u"."email"',
                [$oldKey, $newKey]
            );
        }
    }


    /**
     * Run queries for reverting the changes made in the up() method. Archived accounts are deleted, as they have no
     * email address, which the column then needs. Log entries keep their account IDs.
     *
     * @throws \Programster\PgsqlLib\Exceptions\ExceptionQueryError - if there is an issue with the query.
     * @throws \Programster\PgsqlLib\Exceptions\ExceptionConnectionError - if database is not actually connected.
     */
    public function down(\PgSql\Connection $connectionResource) : void
    {
        $db = new PgSqlConnection($connectionResource);
        $db->query('DELETE FROM "admin_user" WHERE "status" = \'archived\'');

        $db->query(
            'ALTER TABLE "admin_user" DROP CONSTRAINT "admin_user_archived_has_no_email",'
            . ' ALTER COLUMN "email" SET NOT NULL, DROP COLUMN "status_changed_at", DROP COLUMN "status"'
        );
    }
}
