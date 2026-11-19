<?php

/*
 * The "admin_user" table. Use this for loading and deleting AdminUser objects.
 */

declare(strict_types = 1);

use Programster\PgsqlLib\PgSqlConnection;
use Programster\PgsqlObjects\AbstractUuidTable;


class AdminUserTable extends AbstractUuidTable
{
    public function getObjectClassName() : string
    {
        return AdminUser::class;
    }


    public function getDb() : PgSqlConnection
    {
        return ServiceFactory::getDb();
    }


    public function getFieldsThatAllowNull() : array
    {
        return ['email', 'totp_secret', 'totp_last_used_step', 'last_login_at', 'last_login_ip', 'status_changed_at'];
    }


    public function getFieldsThatHaveDefaults() : array
    {
        return ['created_at', 'session_version', 'must_change_password', 'status'];
    }


    public function getVirtualFields() : array
    {
        return [];
    }


    public function getTableName() : string
    {
        return "admin_user";
    }
}
