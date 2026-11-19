<?php

/*
 * How far back a list of emails or rejected requests goes, matching the windows the dashboard counts over, so a count
 * can be clicked to list what it counted. ANY_TIME is no limit.
 */

declare(strict_types = 1);


enum TimeWindow : string
{
    case ANY_TIME = 'any';
    case LAST_MINUTE = 'minute';
    case LAST_HOUR = 'hour';
    case LAST_24_HOURS = '24h';
    case LAST_7_DAYS = '7d';
    case LAST_30_DAYS = '30d';
    case LAST_365_DAYS = '365d';


    /**
     * Get what it's called in a list's filter, e.g. "In the last hour".
     * @return string
     */
    public function getLabel() : string
    {
        return match ($this) {
            TimeWindow::ANY_TIME => 'Any time',
            TimeWindow::LAST_MINUTE => 'In the last minute',
            TimeWindow::LAST_HOUR => 'In the last hour',
            TimeWindow::LAST_24_HOURS => 'In the last 24 hours',
            TimeWindow::LAST_7_DAYS => 'In the last 7 days',
            TimeWindow::LAST_30_DAYS => 'In the last 30 days',
            TimeWindow::LAST_365_DAYS => 'In the last 365 days',
        };
    }


    /**
     * Get the SQL condition that a time column is within it, e.g. "created_at" > now() - interval '1 hour'.
     *
     * @param string $column - the column, escaped, e.g. '"created_at"'.
     *
     * @return string - the condition, which is "TRUE" for ANY_TIME.
     */
    public function getSqlCondition(string $column) : string
    {
        return match ($this) {
            TimeWindow::ANY_TIME => 'TRUE',
            TimeWindow::LAST_MINUTE => "{$column} > now() - interval '1 minute'",
            TimeWindow::LAST_HOUR => "{$column} > now() - interval '1 hour'",
            TimeWindow::LAST_24_HOURS => "{$column} > now() - interval '24 hours'",
            TimeWindow::LAST_7_DAYS => "{$column} > now() - interval '7 days'",
            TimeWindow::LAST_30_DAYS => "{$column} > now() - interval '30 days'",
            TimeWindow::LAST_365_DAYS => "{$column} > now() - interval '365 days'",
        };
    }
}
