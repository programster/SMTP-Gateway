<?php

/*
 * Records when each account last logged in to the web interface, and from which IP address, and the IP address each
 * API key and email token was last used from (when they were last used is already recorded), for the dashboard and the
 * accounts', API keys' and email tokens' pages. Each is NULL until the next login or use.
 */

use Programster\PgsqlLib\PgSqlConnection;


class LastUseMigration implements Programster\PgsqlMigrations\MigrationInterface
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

        $queries = [
            'ALTER TABLE "admin_user" ADD COLUMN "last_login_at" timestamptz NULL',
            'ALTER TABLE "admin_user" ADD COLUMN "last_login_ip" inet NULL',
            'ALTER TABLE "api_key" ADD COLUMN "last_used_ip" inet NULL',
            'ALTER TABLE "email_token" ADD COLUMN "last_used_ip" inet NULL',
        ];

        foreach ($queries as $query)
        {
            $db->query($query);
        }
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

        $queries = [
            'ALTER TABLE "email_token" DROP COLUMN "last_used_ip"',
            'ALTER TABLE "api_key" DROP COLUMN "last_used_ip"',
            'ALTER TABLE "admin_user" DROP COLUMN "last_login_ip"',
            'ALTER TABLE "admin_user" DROP COLUMN "last_login_at"',
        ];

        foreach ($queries as $query)
        {
            $db->query($query);
        }
    }
}
