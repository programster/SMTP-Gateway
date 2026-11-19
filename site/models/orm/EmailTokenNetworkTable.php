<?php

/*
 * The "email_token_network" table. Use this for loading and deleting EmailTokenNetwork objects.
 */

declare(strict_types = 1);

use Programster\PgsqlLib\PgSqlConnection;
use Programster\PgsqlObjects\AbstractUuidTable;


class EmailTokenNetworkTable extends AbstractUuidTable
{
    public function getObjectClassName() : string
    {
        return EmailTokenNetwork::class;
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
        return "email_token_network";
    }
}
