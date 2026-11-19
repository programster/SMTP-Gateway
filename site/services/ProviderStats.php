<?php

/*
 * How much each provider has been handed recently, for the providers page: every attempt, failed ones included, as
 * that's what its rate limits count.
 */

declare(strict_types = 1);


final class ProviderStats
{
    /**
     * Count the attempts each provider has been given recently.
     *
     * @return array - provider name to an array of "last_minute", "last_hour" and "last_24_hours" (emails), and
     * "recipients_last_24_hours". Providers given nothing aren't included.
     */
    public static function loadRecentCounts() : array
    {
        $query = <<<SQL
            SELECT "provider",
                count(*) FILTER (WHERE "dispatched_at" > now() - interval '1 minute') AS "last_minute",
                count(*) FILTER (WHERE "dispatched_at" > now() - interval '1 hour') AS "last_hour",
                count(*) AS "last_24_hours",
                coalesce(sum("recipient_count"), 0) AS "recipients_last_24_hours"
            FROM "email_attempt"
            WHERE "dispatched_at" > now() - interval '24 hours'
            GROUP BY "provider"
            SQL;

        $counts = [];

        foreach (DB::fetchRows($query) as $row)
        {
            $counts[$row['provider']] = array_map('intval', array_diff_key($row, ['provider' => true]));
        }

        return $counts;
    }
}
