<?php

/*
 * The "email_token_address_rule" table. Use this for loading and deleting EmailTokenAddressRuleRecord objects.
 */

declare(strict_types = 1);

use Programster\PgsqlLib\PgSqlConnection;
use Programster\PgsqlObjects\AbstractUuidTable;


class EmailTokenAddressRuleRecordTable extends AbstractUuidTable
{
    public function getObjectClassName() : string
    {
        return EmailTokenAddressRuleRecord::class;
    }


    public function getDb() : PgSqlConnection
    {
        return ServiceFactory::getDb();
    }


    public function getFieldsThatAllowNull() : array
    {
        return ['value'];
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
        return "email_token_address_rule";
    }
}
