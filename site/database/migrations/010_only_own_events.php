<?php

/*
 * Only the events about this service's own emails are kept (see EventRecorder): a provider's webhook can report events
 * for email sent by other systems through the same account (e.g. SendGrid's, for the whole account), which were kept
 * with no email, and counted in the statistics. Those already kept are deleted. They can't be put back.
 */

use Programster\PgsqlLib\PgSqlConnection;


class OnlyOwnEventsMigration implements Programster\PgsqlMigrations\MigrationInterface
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
        $db->query('DELETE FROM "email_event" WHERE "email_message_id" IS NULL');
    }


    /**
     * Run queries for reverting the changes made in the up() method. The deleted events can't be put back, so this
     * does nothing.
     *
     * @throws \Programster\PgsqlLib\Exceptions\ExceptionQueryError - if there is an issue with the query.
     * @throws \Programster\PgsqlLib\Exceptions\ExceptionConnectionError - if database is not actually connected.
     */
    public function down(\PgSql\Connection $connectionResource) : void
    {
    }
}
