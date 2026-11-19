<?php

/*
 * Backup codes: single-use codes an account can log in with instead of its second factor, for when its authenticator
 * app or security key is lost. Only a hash of each is stored, and each can only be used once. They're replaced as a
 * set (see BackupCodeService).
 */

use Programster\PgsqlLib\PgSqlConnection;


class BackupCodesMigration implements Programster\PgsqlMigrations\MigrationInterface
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
            'CREATE TABLE "admin_backup_code" (
                "id" uuid NOT NULL PRIMARY KEY,
                "admin_user_id" uuid NOT NULL REFERENCES "admin_user" ("id") ON DELETE CASCADE,
                "code_hash" text NOT NULL,
                "created_at" timestamptz NOT NULL DEFAULT now(),
                "used_at" timestamptz NULL
            )'
        );

        $db->query('CREATE INDEX "admin_backup_code_admin_user_id" ON "admin_backup_code" ("admin_user_id")');
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
        $db->query('DROP TABLE "admin_backup_code"');
    }
}
