<?php

/*
 * Whether an account has to choose its own password before it can do anything else: set when an administrator gives
 * it a password, and asks for it to be changed when it first logs in, so the password it goes on using is one only its
 * owner knows. Cleared when its password is changed.
 */

use Programster\PgsqlLib\PgSqlConnection;


class MustChangePasswordMigration implements Programster\PgsqlMigrations\MigrationInterface
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
        $db->query('ALTER TABLE "admin_user" ADD COLUMN "must_change_password" boolean NOT NULL DEFAULT false');
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
        $db->query('ALTER TABLE "admin_user" DROP COLUMN "must_change_password"');
    }
}
