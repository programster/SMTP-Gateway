<?php

/*
 * The "email_message" table. Use this for loading and deleting EmailMessage objects.
 */

declare(strict_types = 1);

use Programster\PgsqlLib\PgSqlConnection;
use Programster\PgsqlObjects\AbstractUuidTable;


class EmailMessageTable extends AbstractUuidTable
{
    public function getObjectClassName() : string
    {
        return EmailMessage::class;
    }


    public function getDb() : PgSqlConnection
    {
        return ServiceFactory::getDb();
    }


    public function getFieldsThatAllowNull() : array
    {
        return [
            'email_token_id',
            'raw_message_base64',
            'dispatched_at',
            'sent_at',
            'provider',
            'provider_message_id',
            'last_error',
            'attachments_removed_at',
        ];
    }


    public function getFieldsThatHaveDefaults() : array
    {
        return [
            'subject',
            'was_queued',
            'attempts',
            'next_attempt_at',
            'created_at',
            'tried_providers',
            'retry_round',
            'removed_attachment_count',
            'priority',
        ];
    }


    public function getVirtualFields() : array
    {
        return [];
    }


    public function getTableName() : string
    {
        return "email_message";
    }
}
