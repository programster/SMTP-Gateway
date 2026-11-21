<?php

/*
 * Lets an administrator give an account that has lost its second factor a short grace period, until which its next
 * password login replaces its second factors (see MfaGraceService).
 */

use Programster\PgsqlLib\PgSqlConnection;


class MfaGraceMigration implements Programster\PgsqlMigrations\MigrationInterface
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
        $db->query('ALTER TABLE "admin_user" ADD COLUMN "mfa_grace_until" timestamptz NULL');
    }


    /**
     * Run queries for reverting the changes made in the up() method.
     *
     * @throws \Programster\PgsqlLib\Exceptions\ExceptionQueryError - if there is an issue with the query.
     * @throws \Programster\PgsqlLib\Exceptions\ExceptionConnectionError - if database is not actually connected.
     */
    public function down(\PgSql\Connection $connectionResource) : void
    {
        $db = new PgSqlConnection($connectionResource);
        $db->query('ALTER TABLE "admin_user" DROP COLUMN "mfa_grace_until"');
    }
}
