<?php

/*
 * Searches the application's logs (the "log" table, written by programster/pgsql-logger), a page at a time, and loads
 * one entry, to show in full.
 */

declare(strict_types = 1);


final class LogRepository
{
    /**
     * Find a page of the log entries matching a filter, and how many match in all.
     *
     * @param LogFilter $filter - what to look for.
     *
     * @param LogPage $page - which page, in what order.
     *
     * @return LogSearchResult
     *
     * @throws InvalidInputException - if the filter's regular expression isn't valid, or a time isn't valid.
     */
    public static function search(LogFilter $filter, LogPage $page) : LogSearchResult
    {
        $where = implode(' AND ', $filter->toSqlConditions());
        $limit = $page->getSize()->value;
        $offset = $page->getOffset();
        $order = $page->getSort()->getSqlOrder();

        $query =
            'SELECT "id", "message", "level", "context"::text AS "context", "created_at" FROM "log"'
            . " WHERE {$where} ORDER BY {$order} LIMIT {$limit} OFFSET {$offset}";

        $countQuery = "SELECT count(*) FROM \"log\" WHERE {$where}";

        try
        {
            $rows = DB::fetchRows($query);
            $totalCount = (int)DB::fetchValue($countQuery);
        }
        catch (\Programster\PgsqlLib\Exceptions\ExceptionQueryError $e)
        {
            if ($filter->isRegex() === false)
            {
                throw $e;
            }

            $hasReason = (preg_match('/ERROR:\s*([^\n]+)/', $e->getMessage(), $matches) === 1);
            $reason = $hasReason ? $matches[1] : 'invalid syntax';
            throw new InvalidInputException("That isn't a valid regular expression ({$reason}).");
        }

        $entries = [];

        foreach ($rows as $row)
        {
            $entries[] = new LogEntry($row);
        }

        return new LogSearchResult(new LogEntryCollection(...$entries), $totalCount);
    }


    /**
     * Load one log entry.
     *
     * @param string $id - its ID.
     *
     * @return LogEntry
     *
     * @throws NotFoundException - if there's no such entry, e.g. it has been deleted, as it was past its retention.
     */
    public static function load(string $id) : LogEntry
    {
        $rows = [];

        if (Uuid::isValid($id))
        {
            $query =
                'SELECT "id", "message", "level", "context"::text AS "context", "created_at" FROM "log" WHERE "id" = '
                . DB::escape($id);

            $rows = DB::fetchRows($query);
        }

        if (count($rows) === 0)
        {
            throw new NotFoundException("There's no log entry with the ID \"{$id}\".");
        }

        return new LogEntry($rows[0]);
    }
}
