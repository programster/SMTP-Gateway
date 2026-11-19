<?php

/*
 * The PostgreSQL database that holds the test run's queue of suites (programster/pgsql-task-queue). run-tests.sh
 * starts it in a container of its own for each run, and passes where it is in the QUEUE_DB_* environment variables.
 */

declare(strict_types = 1);

use Programster\PgsqlLib\PgSqlConnection;


final class QueueDatabase
{
    # The queue that the suites are put in, and the workers take them from.
    public const QUEUE_NAME = 'suites';


    /**
     * Open a new connection to the queue's database. Each process needs its own, as a worker listens for
     * notifications on it.
     * @return PgSqlConnection
     * @throws RuntimeException - if a QUEUE_DB_* environment variable isn't set.
     * @throws \Programster\PgsqlLib\Exceptions\ExceptionConnectionError - if it can't connect.
     */
    public static function connect() : PgSqlConnection
    {
        return PgSqlConnection::create(
            host: self::getSetting('QUEUE_DB_HOST'),
            dbName: self::getSetting('QUEUE_DB_NAME'),
            user: self::getSetting('QUEUE_DB_USER'),
            password: self::getSetting('QUEUE_DB_PASSWORD'),
            forceNew: true,
            port: (int)self::getSetting('QUEUE_DB_PORT')
        );
    }


    /**
     * Get one of the QUEUE_DB_* environment variables.
     * @param string $name - its name.
     * @return string
     * @throws RuntimeException - if it isn't set.
     */
    private static function getSetting(string $name) : string
    {
        $value = getenv($name);

        if (!is_string($value) || $value === '')
        {
            throw new RuntimeException("{$name} isn't set. Run the tests with tests/run-tests.sh, which sets it.");
        }

        return $value;
    }
}
