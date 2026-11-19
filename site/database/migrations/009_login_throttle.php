<?php

/*
 * Failed logins are counted per IP address (or per IPv6 /64, which one machine usually has all of), so an address that
 * fails too often is refused for a while, whichever accounts it tries (see LoginThrottle). Each address has one row:
 * how many attempts it's failed in its current window, and when that window started.
 */

use Programster\PgsqlLib\PgSqlConnection;


class LoginThrottleMigration implements Programster\PgsqlMigrations\MigrationInterface
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
            'CREATE TABLE "login_throttle" ('
            . ' "source" varchar(64) PRIMARY KEY,'
            . ' "failures" integer NOT NULL CHECK ("failures" >= 0),'
            . ' "window_started_at" timestamptz NOT NULL'
            . ')'
        );
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
        $db->query('DROP TABLE "login_throttle"');
    }
}
