<?php

/*
 * The "email_token_rate_limit" table. Use this for loading and deleting EmailTokenRateLimit objects.
 */

declare(strict_types = 1);

use Programster\PgsqlLib\PgSqlConnection;
use Programster\PgsqlObjects\AbstractUuidTable;


class EmailTokenRateLimitTable extends AbstractUuidTable
{
    public function getObjectClassName() : string
    {
        return EmailTokenRateLimit::class;
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
        return "email_token_rate_limit";
    }
}
