<?php

/*
 * The "global_rate_limit" table. Use this for loading and deleting GlobalRateLimit objects.
 */

declare(strict_types = 1);

use Programster\PgsqlLib\PgSqlConnection;
use Programster\PgsqlObjects\AbstractUuidTable;


class GlobalRateLimitTable extends AbstractUuidTable
{
    public function getObjectClassName() : string
    {
        return GlobalRateLimit::class;
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
        return ['unit'];
    }


    public function getVirtualFields() : array
    {
        return [];
    }


    public function getTableName() : string
    {
        return "global_rate_limit";
    }
}
