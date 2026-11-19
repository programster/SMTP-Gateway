<?php

/*
 * The "rejected_request" table. Use this for loading and deleting RejectedRequest objects.
 */

declare(strict_types = 1);

use Programster\PgsqlLib\PgSqlConnection;
use Programster\PgsqlObjects\AbstractUuidTable;


class RejectedRequestTable extends AbstractUuidTable
{
    public function getObjectClassName() : string
    {
        return RejectedRequest::class;
    }


    public function getDb() : PgSqlConnection
    {
        return ServiceFactory::getDb();
    }


    public function getFieldsThatAllowNull() : array
    {
        return ['email_token_id'];
    }


    public function getFieldsThatHaveDefaults() : array
    {
        return ['detail', 'created_at'];
    }


    public function getVirtualFields() : array
    {
        return [];
    }


    public function getTableName() : string
    {
        return "rejected_request";
    }
}
