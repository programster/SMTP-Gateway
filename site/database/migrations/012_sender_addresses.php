<?php

/*
 * Lets a provider be restricted to particular senders (see ProviderDefinition::takesAll()).
 *
 * Each email remembers every address it's sent as (sender_addresses): its envelope sender, and the addresses in its
 * From and Sender headers, so the queue workers can tell which providers take it without parsing the message. Emails
 * already stored get just their envelope sender, as their headers would have to be parsed to find the others, which
 * only matters for emails still in the queue when a provider is first restricted.
 *
 * An email that no provider would take is refused when it's sent to the service, so rejected_request gets a reason
 * for that: no_provider_for_sender.
 */

use Programster\PgsqlLib\PgSqlConnection;


class SenderAddressesMigration implements Programster\PgsqlMigrations\MigrationInterface
{
    private const string ADD_REASON_CHECK =
        'ALTER TABLE "rejected_request" ADD CONSTRAINT "rejected_request_reason_check" CHECK ("reason" IN (%s))';

    private const string REASONS_BEFORE =
        "'invalid_credentials', 'revoked_email_token', 'ip_not_allowed', 'sender_not_allowed',"
        . " 'reply_to_not_allowed', 'recipient_not_allowed', 'message_too_large', 'invalid_message',"
        . " 'provider_rejected', 'too_many_recipients'";


    /**
     * Run queries for upgrading the database version.
     *
     * @throws \Programster\PgsqlLib\Exceptions\ExceptionQueryError - if there is an issue with the query.
     * @throws \Programster\PgsqlLib\Exceptions\ExceptionConnectionError - if database is not actually connected.
     */
    public function up(\PgSql\Connection $connectionResource) : void
    {
        $db = new PgSqlConnection($connectionResource);
        $reasons = self::REASONS_BEFORE . ", 'no_provider_for_sender'";

        $queries = [
            'ALTER TABLE "email_message" ADD COLUMN "sender_addresses" jsonb NOT NULL DEFAULT \'[]\'',
            'UPDATE "email_message" SET "sender_addresses" = jsonb_build_array("envelope_sender")',
            'ALTER TABLE "rejected_request" DROP CONSTRAINT "rejected_request_reason_check"',
            sprintf(self::ADD_REASON_CHECK, $reasons),
        ];

        foreach ($queries as $query)
        {
            $db->query($query);
        }
    }


    /**
     * Run queries for reverting the changes made in the up() method. Rejections for no provider taking the sender
     * become "sender_not_allowed", the nearest reason there was before.
     *
     * @throws \Programster\PgsqlLib\Exceptions\ExceptionQueryError - if there is an issue with the query.
     * @throws \Programster\PgsqlLib\Exceptions\ExceptionConnectionError - if database is not actually connected.
     */
    public function down(\PgSql\Connection $connectionResource) : void
    {
        $db = new PgSqlConnection($connectionResource);
        $reasons = self::REASONS_BEFORE;
        $newReason = "'no_provider_for_sender'";

        $queries = [
            'ALTER TABLE "rejected_request" DROP CONSTRAINT "rejected_request_reason_check"',
            "UPDATE \"rejected_request\" SET \"reason\" = 'sender_not_allowed' WHERE \"reason\" = {$newReason}",
            sprintf(self::ADD_REASON_CHECK, $reasons),
            'ALTER TABLE "email_message" DROP COLUMN "sender_addresses"',
        ];

        foreach ($queries as $query)
        {
            $db->query($query);
        }
    }
}
