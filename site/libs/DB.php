<?php

/*
 * Helpers for running queries on the database connection from ServiceFactory::getDb(). This class never creates a
 * connection of its own.
 */

declare(strict_types = 1);

use Programster\PgsqlLib\Exceptions\ExceptionQueryError;
use Programster\PgsqlLib\Query;


class DB
{
    // The error code of a query from generateRequireRowQuery() that found no row.
    private const string NO_ROW_SQLSTATE = 'SG001';


    /**
     * Generate a query that runs an UPDATE or DELETE and fails if it changed no rows. Put it in a transaction to check
     * and lock a row before the rest runs: if it fails, nothing in the transaction takes effect.
     *
     * @param string $statement - the UPDATE or DELETE, with every value already escaped (see escape()).
     *
     * @return string - the query. See isNoRowError() for telling its failure apart from any other.
     */
    public static function generateRequireRowQuery(string $statement) : string
    {
        // A random tag, so nothing in the statement can end the quoted block early.
        $tag = '$guard' . bin2hex(random_bytes(8)) . '$';
        $state = self::NO_ROW_SQLSTATE;
        $check = "IF NOT FOUND THEN RAISE EXCEPTION 'No matching row.' USING ERRCODE = '{$state}'; END IF;";
        return "DO {$tag} BEGIN {$statement}; {$check} END {$tag}";
    }


    /**
     * Check whether a query failed because a query from generateRequireRowQuery() found no row.
     * @param ExceptionQueryError $exception - the error.
     * @return bool
     */
    public static function isNoRowError(ExceptionQueryError $exception) : bool
    {
        return $exception->getSqlState() === self::NO_ROW_SQLSTATE;
    }


    /**
     * Generate an insert of one row, with its values sent as parameters, so a large one isn't copied into the SQL.
     *
     * @param string $table - the table's name.
     *
     * @param array<string, string|int|float|bool|null> $row - the column names and their values.
     *
     * @return Query
     */
    public static function generateInsertQuery(string $table, array $row) : Query
    {
        $db = ServiceFactory::getDb();
        $columns = implode(', ', $db->escapeIdentifiers(array_keys($row)));
        $placeholders = [];

        for ($position = 1; $position <= count($row); $position++)
        {
            $placeholders[] = '$' . $position;
        }

        $values = implode(', ', $placeholders);
        $sql = "INSERT INTO {$db->escapeIdentifier($table)} ({$columns}) VALUES ({$values})";
        return new Query($sql, array_values($row));
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
