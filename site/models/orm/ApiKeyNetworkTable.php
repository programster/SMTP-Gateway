<?php

/*
 * The "api_key_network" table. Use this for loading and deleting ApiKeyNetwork objects.
 */

declare(strict_types = 1);

use Programster\PgsqlLib\PgSqlConnection;
use Programster\PgsqlObjects\AbstractUuidTable;


class ApiKeyNetworkTable extends AbstractUuidTable
{
    public function getObjectClassName() : string
    {
        return ApiKeyNetwork::class;
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
        return "api_key_network";
    }
}
