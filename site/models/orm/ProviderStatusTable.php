<?php

/*
 * The "provider_status" table. Only used for generating IDs: ProviderStatusRepository reads and writes it directly,
 * as it needs to upsert.
 */

declare(strict_types = 1);

use Programster\PgsqlLib\PgSqlConnection;
use Programster\PgsqlObjects\AbstractUuidTable;


class ProviderStatusTable extends AbstractUuidTable
{
    public function getObjectClassName() : string
    {
        return ProviderStatus::class;
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
        return ['last_error', 'updated_at'];
    }


    public function getVirtualFields() : array
    {
        return [];
    }


    public function getTableName() : string
    {
        return "provider_status";
    }
}
