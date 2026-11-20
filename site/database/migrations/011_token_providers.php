<?php

/*
 * Lets an email token be restricted to particular providers, so its emails are only ever sent through them.
 *
 * Providers live in settings.yaml, not the database, so there's no foreign key: each is linked by its id (a UUID in
 * settings.yaml, which stays the same when the provider is renamed). Removing a provider removes its links.
 *
 * restricts_providers says whether a token is restricted at all. It can't be left to whether it has any links, as the
 * address and IP conditions are, because removing a provider can take away a token's last link, and that mustn't turn a
 * restricted token into one that can use every provider. A restricted token with no links can't send through any, so
 * its emails wait in the queue.
 */

use Programster\PgsqlLib\PgSqlConnection;


class TokenProvidersMigration implements Programster\PgsqlMigrations\MigrationInterface
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
            'ALTER TABLE "email_token" ADD COLUMN "restricts_providers" boolean NOT NULL DEFAULT false',

            'CREATE TABLE "email_token_provider" (
                "id" uuid NOT NULL PRIMARY KEY,
                "email_token_id" varchar(19) NOT NULL REFERENCES "email_token" ("id") ON DELETE CASCADE,
                "provider_id" uuid NOT NULL,
                UNIQUE ("email_token_id", "provider_id")
            )',

            // For finding the tokens linked to a provider, e.g. when it's removed.
            'CREATE INDEX "email_token_provider_provider_id" ON "email_token_provider" ("provider_id")',
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
            'DROP TABLE "email_token_provider"',
            'ALTER TABLE "email_token" DROP COLUMN "restricts_providers"',
        ];

        foreach ($queries as $query)
        {
            $db->query($query);
        }
    }
}
