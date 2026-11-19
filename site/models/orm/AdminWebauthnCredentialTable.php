<?php

/*
 * The "admin_webauthn_credential" table. Use this for loading and deleting AdminWebauthnCredential objects.
 */

declare(strict_types = 1);

use Programster\PgsqlLib\PgSqlConnection;
use Programster\PgsqlObjects\AbstractUuidTable;


class AdminWebauthnCredentialTable extends AbstractUuidTable
{
    public function getObjectClassName() : string
    {
        return AdminWebauthnCredential::class;
    }


    public function getDb() : PgSqlConnection
    {
        return ServiceFactory::getDb();
    }


    public function getFieldsThatAllowNull() : array
    {
        return ['last_used_at'];
    }


    public function getFieldsThatHaveDefaults() : array
    {
        return ['signature_counter', 'created_at'];
    }


    public function getVirtualFields() : array
    {
        return [];
    }


    public function getTableName() : string
    {
        return "admin_webauthn_credential";
    }
}
