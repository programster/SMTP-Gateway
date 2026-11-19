<?php

/*
 * Cloudflare's address ranges, as last fetched from Cloudflare (see CloudflareNetworks), so that when the site is
 * behind Cloudflare, a request from one of them has its CF-Connecting-IP header believed. They're kept here, rather
 * than in settings.yaml, as they're Cloudflare's to change, and are fetched again on startup and every few hours.
 */

use Programster\PgsqlLib\PgSqlConnection;


class CloudflareNetworksMigration implements Programster\PgsqlMigrations\MigrationInterface
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
            'CREATE TABLE "cloudflare_network" (
                "network" cidr NOT NULL PRIMARY KEY,
                "fetched_at" timestamptz NOT NULL
            )'
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
        $db->query('DROP TABLE "cloudflare_network"');
    }
}
