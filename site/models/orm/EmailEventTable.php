<?php

/*
 * The "email_event" table. Use this for loading and deleting EmailEvent objects.
 */

declare(strict_types = 1);

use Programster\PgsqlLib\PgSqlConnection;
use Programster\PgsqlObjects\AbstractUuidTable;


class EmailEventTable extends AbstractUuidTable
{
    public function getObjectClassName() : string
    {
        return EmailEvent::class;
    }


    public function getDb() : PgSqlConnection
    {
        return ServiceFactory::getDb();
    }


    public function getFieldsThatAllowNull() : array
    {
        return ['email_message_id', 'provider_event_id', 'recipient', 'detail'];
    }


    public function getFieldsThatHaveDefaults() : array
    {
        return ['created_at'];
    }


    public function getVirtualFields() : array
    {
        return [];
    }


    public function getTableName() : string
    {
        return "email_event";
    }
}
