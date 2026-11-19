<?php

/*
 * Lets an attempt to send an email end as "rate_limited": the provider refused it as over its own rate limit (or
 * quota), rather than anything about the email (see RateLimitReply).
 */

use Programster\PgsqlLib\PgSqlConnection;


class RateLimitedAttemptsMigration implements Programster\PgsqlMigrations\MigrationInterface
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
        $db->query('ALTER TABLE "email_attempt" DROP CONSTRAINT "email_attempt_outcome_check"');

        $db->query(
            'ALTER TABLE "email_attempt" ADD CONSTRAINT "email_attempt_outcome_check" CHECK ("outcome" IN ('
            . "'sending', 'sent', 'temporary_failure', 'refused', 'unavailable', 'rate_limited', 'interrupted'))"
        );
    }


    /**
     * Run queries for reverting the changes made in the up() method. Rate limited attempts become temporary failures.
     *
     * @throws \Programster\PgsqlLib\Exceptions\ExceptionQueryError - if there is an issue with the query.
     * @throws \Programster\PgsqlLib\Exceptions\ExceptionConnectionError - if database is not actually connected.
     */
    public function down(\PgSql\Connection $connectionResource) : void
    {
        $db = new PgSqlConnection($connectionResource);
        $db->query('ALTER TABLE "email_attempt" DROP CONSTRAINT "email_attempt_outcome_check"');
        $db->query("UPDATE \"email_attempt\" SET \"outcome\" = 'temporary_failure' WHERE \"outcome\" = 'rate_limited'");

        $db->query(
            'ALTER TABLE "email_attempt" ADD CONSTRAINT "email_attempt_outcome_check" CHECK ("outcome" IN ('
            . "'sending', 'sent', 'temporary_failure', 'refused', 'unavailable', 'interrupted'))"
        );
    }
}
