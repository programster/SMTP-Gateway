<?php

/*
 * The "email_token_provider" table. Use this for loading and deleting EmailTokenProvider objects.
 */

declare(strict_types = 1);

use Programster\PgsqlLib\PgSqlConnection;
use Programster\PgsqlObjects\AbstractUuidTable;


class EmailTokenProviderTable extends AbstractUuidTable
{
    public function getObjectClassName() : string
    {
        return EmailTokenProvider::class;
    }


    public function getDb() : PgSqlConnection
    {
        return ServiceFactory::getDb();
    }


    public function getFieldsThatAllowNull() : array
    {
        return [];
    }


    public function getFieldsThatHaveDefaults() : array
    {
        return [];
    }


    public function getVirtualFields() : array
    {
        return [];
    }


    public function getTableName() : string
    {
        return "email_token_provider";
    }
}
