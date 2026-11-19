<?php

/*
 * Makes an email token's conditions optional: a part of the email (or where it's used from) with no rules has no
 * restriction, so the "any" rule ("*") is no longer needed. Every rule for a field that had an "any" rule is removed,
 * as "any" already allowed everyone, which leaves the field with no condition: the same behaviour.
 */

use Programster\PgsqlLib\PgSqlConnection;


class OptionalConditionsMigration implements Programster\PgsqlMigrations\MigrationInterface
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
            'DELETE FROM "email_token_address_rule" AS "r" WHERE EXISTS ('
            . 'SELECT 1 FROM "email_token_address_rule" AS "a"'
            . " WHERE \"a\".\"email_token_id\" = \"r\".\"email_token_id\" AND \"a\".\"field\" = \"r\".\"field\""
            . " AND \"a\".\"kind\" = 'any')"
        );

        foreach (self::findCheckConstraints($db) as $name)
        {
            $db->query('ALTER TABLE "email_token_address_rule" DROP CONSTRAINT "' . $name . '"');
        }

        $db->query(
            'ALTER TABLE "email_token_address_rule" ADD CONSTRAINT "email_token_address_rule_field_check"'
            . " CHECK (\"field\" IN ('sender', 'reply_to', 'recipient'))"
        );

        $db->query(
            'ALTER TABLE "email_token_address_rule" ADD CONSTRAINT "email_token_address_rule_kind_check"'
            . " CHECK (\"kind\" IN ('domain', 'address'))"
        );

        $db->query('ALTER TABLE "email_token_address_rule" ALTER COLUMN "value" SET NOT NULL');
    }


    /**
     * Run queries for reverting the changes made in the up() method. The removed "any" rules aren't put back, so a
     * field with no rules would then allow nobody: give such tokens a "*" rule again, or recreate them.
     *
     * @throws \Programster\PgsqlLib\Exceptions\ExceptionQueryError - if there is an issue with the query.
     * @throws \Programster\PgsqlLib\Exceptions\ExceptionConnectionError - if database is not actually connected.
     */
    public function down(\PgSql\Connection $connectionResource) : void
    {
        $db = new PgSqlConnection($connectionResource);
        $db->query('ALTER TABLE "email_token_address_rule" ALTER COLUMN "value" DROP NOT NULL');
        $db->query('ALTER TABLE "email_token_address_rule" DROP CONSTRAINT "email_token_address_rule_kind_check"');

        $db->query(
            'ALTER TABLE "email_token_address_rule" ADD CONSTRAINT "email_token_address_rule_kind_check"'
            . " CHECK (\"kind\" IN ('any', 'domain', 'address'))"
        );

        $db->query(
            'ALTER TABLE "email_token_address_rule" ADD CONSTRAINT "email_token_address_rule_check"'
            . " CHECK ((\"kind\" = 'any') = (\"value\" IS NULL))"
        );
    }


    /**
     * Find the names of the table's CHECK constraints, which PostgreSQL named when the table was created.
     * @return list<string>
     */
    private static function findCheckConstraints(PgSqlConnection $db) : array
    {
        $result = $db->query(
            "SELECT \"conname\" FROM \"pg_constraint\" WHERE \"conrelid\" = '\"email_token_address_rule\"'::regclass"
            . " AND \"contype\" = 'c'"
        );

        return array_column(pg_fetch_all($result) ?: [], 'conname');
    }
}
