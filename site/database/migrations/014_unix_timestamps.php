<?php

/*
 * Stores every time as a UTC Unix timestamp, in seconds, rather than as a timestamptz. The times the queue estimate and
 * the rate limits work with (when an email or attempt was dispatched and finished) are double precision, as they need
 * fractions of a second. Every other time is a bigint, rounded down to a whole second. The log table already uses Unix
 * timestamps, and belongs to programster/pgsql-logger, so it's left alone.
 *
 * unix_time() and unix_time_precise() give the current time in those two forms. They're based on now(), so stay the
 * same throughout a transaction, as now() does.
 */

use Programster\PgsqlLib\PgSqlConnection;
use Programster\PgsqlLib\Transaction;


class UnixTimestampsMigration implements Programster\PgsqlMigrations\MigrationInterface
{
    // Each table's whole-second columns, and whether each has a default of the current time.
    private const array BIGINT_COLUMNS = [
        'admin_user' => [
            'created_at' => true,
            'last_login_at' => false,
            'status_changed_at' => false,
            'mfa_grace_until' => false,
        ],
        'admin_webauthn_credential' => ['created_at' => true, 'last_used_at' => false],
        'admin_backup_code' => ['created_at' => true, 'used_at' => false],
        'password_reset_token' => ['created_at' => true, 'expires_at' => false, 'used_at' => false],
        'api_key' => ['created_at' => true, 'last_used_at' => false, 'revoked_at' => false],
        'email_token' => ['created_at' => true, 'last_used_at' => false, 'revoked_at' => false],
        'email_message' => ['next_attempt_at' => true, 'created_at' => true, 'attachments_removed_at' => false],
        'email_event' => ['occurred_at' => false, 'created_at' => true],
        'rejected_request' => ['created_at' => true],
        'provider_status' => ['unavailable_until' => false, 'updated_at' => true],
        'cloudflare_network' => ['fetched_at' => false],
        'login_throttle' => ['window_started_at' => false],
    ];

    // Each table's columns with fractions of a second, and whether each has a default of the current time.
    private const array DOUBLE_COLUMNS = [
        'email_message' => ['dispatched_at' => false, 'sent_at' => false],
        'email_attempt' => ['dispatched_at' => true, 'finished_at' => false],
    ];


    /**
     * Run queries for upgrading the database version. It's all one transaction, so a failure changes nothing.
     *
     * @throws \Programster\PgsqlLib\Exceptions\ExceptionQueryError - if there is an issue with the query.
     * @throws \Programster\PgsqlLib\Exceptions\ExceptionConnectionError - if database is not actually connected.
     */
    public function up(\PgSql\Connection $connectionResource) : void
    {
        $db = new PgSqlConnection($connectionResource);

        $queries = [
            'CREATE FUNCTION "unix_time"() RETURNS bigint LANGUAGE sql STABLE PARALLEL SAFE'
            . ' AS $$ SELECT floor(extract(epoch FROM now()))::bigint $$',

            'CREATE FUNCTION "unix_time_precise"() RETURNS double precision LANGUAGE sql STABLE PARALLEL SAFE'
            . ' AS $$ SELECT extract(epoch FROM now())::double precision $$',
        ];

        foreach (array_keys(self::BIGINT_COLUMNS + self::DOUBLE_COLUMNS) as $table)
        {
            $changes = [];

            foreach (self::BIGINT_COLUMNS[$table] ?? [] as $column => $hasDefault)
            {
                $using = "floor(extract(epoch FROM \"{$column}\"))::bigint";
                $changes[] = self::generateColumnChange($column, 'bigint', $using, $hasDefault, 'unix_time()');
            }

            foreach (self::DOUBLE_COLUMNS[$table] ?? [] as $column => $hasDefault)
            {
                $using = "extract(epoch FROM \"{$column}\")::double precision";
                $default = 'unix_time_precise()';
                $changes[] = self::generateColumnChange($column, 'double precision', $using, $hasDefault, $default);
            }

            $queries[] = "ALTER TABLE \"{$table}\" " . implode(', ', $changes);
        }

        (new Transaction($db, $queries))->commit();
    }


    /**
     * Run queries for reverting the changes made in the up() method. The fractions of a second that the whole-second
     * columns lost can't be brought back.
     *
     * @throws \Programster\PgsqlLib\Exceptions\ExceptionQueryError - if there is an issue with the query.
     * @throws \Programster\PgsqlLib\Exceptions\ExceptionConnectionError - if database is not actually connected.
     */
    public function down(\PgSql\Connection $connectionResource) : void
    {
        $db = new PgSqlConnection($connectionResource);
        $queries = [];

        foreach (array_keys(self::BIGINT_COLUMNS + self::DOUBLE_COLUMNS) as $table)
        {
            $changes = [];
            $columns = (self::BIGINT_COLUMNS[$table] ?? []) + (self::DOUBLE_COLUMNS[$table] ?? []);

            foreach ($columns as $column => $hasDefault)
            {
                $using = "to_timestamp(\"{$column}\"::double precision)";
                $changes[] = self::generateColumnChange($column, 'timestamptz', $using, $hasDefault, 'now()');
            }

            $queries[] = "ALTER TABLE \"{$table}\" " . implode(', ', $changes);
        }

        // Only now, as the columns' defaults depended on them.
        $queries[] = 'DROP FUNCTION "unix_time_precise"()';
        $queries[] = 'DROP FUNCTION "unix_time"()';
        (new Transaction($db, $queries))->commit();
    }


    /**
     * Generate the part of an ALTER TABLE that changes a column's type, along with its default, if it has one, as the
     * old default can't be converted to the new type.
     *
     * @param string $column - the column.
     *
     * @param string $type - its new type.
     *
     * @param string $using - the SQL expression converting its existing values.
     *
     * @param bool $hasDefault - whether it has a default of the current time.
     *
     * @param string $default - the SQL for the current time, in the new type.
     *
     * @return string
     */
    private static function generateColumnChange(
        string $column,
        string $type,
        string $using,
        bool $hasDefault,
        string $default
    ) : string
    {
        $change = "ALTER COLUMN \"{$column}\" TYPE {$type} USING {$using}";

        if ($hasDefault)
        {
            $change =
                "ALTER COLUMN \"{$column}\" DROP DEFAULT, {$change},"
                . " ALTER COLUMN \"{$column}\" SET DEFAULT {$default}";
        }

        return $change;
    }
}
