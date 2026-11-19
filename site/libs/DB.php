<?php

/*
 * Helpers for running queries on the database connection from ServiceFactory::getDb(). This class never creates a
 * connection of its own.
 */

declare(strict_types = 1);


class DB
{
    /**
     * Run a callback in a transaction, committing if it returns, and rolling back if it throws.
     *
     * @param Closure $callback - the work to do. It's given no parameters.
     *
     * @return mixed - whatever the callback returned.
     *
     * @throws Throwable - whatever the callback threw, after rolling back.
     */
    public static function transaction(Closure $callback) : mixed
    {
        $db = ServiceFactory::getDb();
        $db->query('BEGIN');

        try
        {
            $result = $callback();
            $db->query('COMMIT');
        }
        catch (Throwable $e)
        {
            try
            {
                $db->query('ROLLBACK');
            }
            catch (\Programster\PgsqlLib\Exceptions\ExceptionQueryError)
            {
                // The connection may have gone, which also ends the transaction. Report the original error.
            }

            throw $e;
        }

        return $result;
    }


    /**
     * Run a query and get all of its rows.
     *
     * @param string $query - the query, with every value already escaped (see escape()).
     *
     * @return array - the rows, each an array of column name to value (as strings, or null).
     */
    public static function fetchRows(string $query) : array
    {
        $result = ServiceFactory::getDb()->query($query);
        $rows = pg_fetch_all($result);
        return ($rows === false) ? [] : $rows;
    }


    /**
     * Run a query and get the value in the first column of its first row.
     *
     * @param string $query - the query, with every value already escaped (see escape()).
     *
     * @return string - the value.
     *
     * @throws Exception - if the query returned no rows, or the value was null.
     */
    public static function fetchValue(string $query) : string
    {
        $value = pg_fetch_result(ServiceFactory::getDb()->query($query), 0, 0);

        if ($value === false || $value === null)
        {
            throw new Exception("Query returned no value: {$query}");
        }

        return $value;
    }


    /**
     * Escape a string as a literal for use in a query, e.g. "it's" becomes "'it''s'".
     *
     * @param string $value - the value.
     *
     * @return string - the quoted, escaped literal.
     */
    public static function escape(string $value) : string
    {
        return pg_escape_literal(ServiceFactory::getDb()->getResource(), $value);
    }
}
