<?php

/*
 * The one place that creates the service's shared objects: the database connections, and the logger. Each is
 * created the first time it's asked for, kept in a static variable, and the same instance is returned every time
 * after that. Nothing else in the codebase opens a database connection.
 */

declare(strict_types = 1);

use Programster\Log\CachingLoggerFactory;
use Programster\Log\FailoverLogger;
use Programster\Log\JsonFileLogger;
use Programster\PgsqlLib\PgSqlConnection;
use Programster\PgsqlLogger\PgSqlLogger;


class ServiceFactory
{
    /**
     * Get the connection to the database, which every table, repository and query uses. It's created the first
     * time it's asked for, and the same instance is returned every time after that.
     *
     * It's opened with forceNew: true, so it's always its own connection, never one that PHP happens to have open
     * already with the same details (which would share its transactions and session settings).
     *
     * A forked process (each SMTP connection is one) gets a connection of its own the first time it asks, rather
     * than its parent's, as two processes using one connection would corrupt each other's queries. Within a
     * process, it's always the same instance.
     *
     * That only protects the child, though. A process must never fork while it has a connection open: when the
     * child exits, PHP closes the connection it inherited, and PostgreSQL's client library then tells the server
     * to end the session, which is the parent's session too. So the SMTP server's parent never connects, and
     * checks isDbConnected() before each fork.
     *
     * @return PgSqlConnection
     *
     * @throws \Programster\PgsqlLib\Exceptions\ExceptionConnectionError - if it can't connect. Nothing is cached,
     * so the next call tries again (as scripts/wait-for-database.php relies on).
     */
    public static function getDb() : PgSqlConnection
    {
        static $db = null;
        static $dbPid = 0;

        if ($db === null || $dbPid !== getmypid())
        {
            $db = self::connect();
            $dbPid = getmypid();
        }

        return $db;
    }


    /**
     * Whether this process has opened either database connection (the main one, or the logger's). A process that
     * forks (the SMTP server) checks this first, as forking with a connection open would break it (see getDb()).
     *
     * @return bool
     */
    public static function isDbConnected() : bool
    {
        return self::trackConnections(false);
    }


    /**
     * Get the logger, which writes to the "log" table (viewable in the web interface), falling back to
     * /logs/app.log if the database can't be written to (with an error log saying why), using programster/log's
     * FailoverLogger. It's created the first time it's asked for (once per process, as with getDb()), and the same
     * instance is returned every time after that. Each of its loggers is only created when it's first needed, so a
     * process that never logs never opens the logger's database connection (which the SMTP server's parent must
     * never do, see getDb()). While the database can't be logged to, it's skipped for a few seconds at a time, so
     * logs go straight to the file, rather than each waiting on it first.
     *
     * Every entry is marked with what it's about (see LogCategory), so the logs can be filtered by it.
     *
     * @param LogCategory $category - what the entries logged with it are about.
     *
     * @return \Psr\Log\LoggerInterface
     */
    public static function getLogger(LogCategory $category) : \Psr\Log\LoggerInterface
    {
        return new CategorisedLogger(self::getUncategorisedLogger(), $category);
    }


    /**
     * The logger every CategorisedLogger writes with: see getLogger().
     * @return \Psr\Log\LoggerInterface
     */
    private static function getUncategorisedLogger() : \Psr\Log\LoggerInterface
    {
        static $logger = null;
        static $loggerPid = 0;

        if ($logger === null || $loggerPid !== getmypid())
        {
            $createDatabaseLogger = function () : PgSqlLogger {
                return new PgSqlLogger(self::getLoggingDb()->getResource(), clock: new SystemClock());
            };

            $createFileLogger = function () : JsonFileLogger {
                return new JsonFileLogger("/logs/app.log");
            };

            $databaseLoggerFactory = new CachingLoggerFactory($createDatabaseLogger);
            $logger = FailoverLogger::create($databaseLoggerFactory, new CachingLoggerFactory($createFileLogger));
            $loggerPid = getmypid();
        }

        return $logger;
    }


    /**
     * Get the logger's connection to the database. This is deliberately a second connection, separate from
     * getDb(), so that a log written in the middle of a transaction is kept even if that transaction is rolled
     * back (which is often exactly when there's something worth logging). Like getDb(), it's opened with
     * forceNew: true, created once per process, and the same instance is returned every time after that.
     *
     * @return PgSqlConnection
     *
     * @throws \Programster\PgsqlLib\Exceptions\ExceptionConnectionError - if it can't connect. Nothing is cached,
     * so the next call tries again.
     */
    private static function getLoggingDb() : PgSqlConnection
    {
        static $db = null;
        static $dbPid = 0;

        if ($db === null || $dbPid !== getmypid())
        {
            $db = self::connect();
            $dbPid = getmypid();
        }

        return $db;
    }


    /**
     * Open a new connection to the database. Only getDb() and getLoggingDb() call this, and each caches what it
     * returns.
     *
     * @return PgSqlConnection
     *
     * @throws \Programster\PgsqlLib\Exceptions\ExceptionConnectionError - if it can't connect.
     */
    private static function connect() : PgSqlConnection
    {
        $connection = PgSqlConnection::create(
            host: DB_HOST,
            dbName: DB_NAME,
            user: DB_USER,
            password: DB_PASSWORD,
            forceNew: true
        );

        self::trackConnections(true);
        return $connection;
    }


    /**
     * Record, or check, whether this process has opened a database connection.
     *
     * @param bool $hasJustConnected - true to record that a connection was just opened, or false to only check.
     *
     * @return bool - whether this process has opened a connection.
     */
    private static function trackConnections(bool $hasJustConnected) : bool
    {
        static $connectedPid = 0;

        if ($hasJustConnected)
        {
            $connectedPid = getmypid();
        }

        return $connectedPid === getmypid();
    }
}
