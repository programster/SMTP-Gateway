<?php

/*
 * The statistics shown on the dashboard and returned by the API: how much email is coming through, from which
 * tokens, and what happened to it.
 */

declare(strict_types = 1);

use Programster\PgsqlLib\PgsqlLib;
use Programster\PgsqlObjects\CacheMode;


final class Stats
{
    /**
     * Get the activity counts for one token, or for everything.
     *
     * @param string $emailTokenId - the token's ID, or an empty string for every token.
     *
     * @return ActivityCounts
     */
    public static function getActivity(string $emailTokenId = '') : ActivityCounts
    {
        $escapedId = ServiceFactory::getDb()->escapeValue($emailTokenId);
        $messageFilter = ($emailTokenId === '') ? 'TRUE' : '"email_token_id" = ' . $escapedId;
        $eventFilter = ($emailTokenId === '') ? 'TRUE' : '"m"."email_token_id" = ' . $escapedId;
        $byService = RejectionOrigin::THIS_SERVICE->getSqlCondition();
        $byProvider = RejectionOrigin::PROVIDER->getSqlCondition();
        $failedAfterQueueing = MessageProgress::FAILED_AFTER_QUEUEING->getSqlCondition();
        $retrying = MessageProgress::RETRYING->getSqlCondition();

        $query = <<<SQL
            SELECT
                (SELECT count(*) FROM "email_message"
                    WHERE {$messageFilter} AND "created_at" > unix_time() - 60) AS "last_minute",
                (SELECT count(*) FROM "email_message"
                    WHERE {$messageFilter} AND "created_at" > unix_time() - 3600) AS "last_hour",
                (SELECT count(*) FROM "email_message"
                    WHERE {$messageFilter} AND "created_at" > unix_time() - 86400) AS "last_24_hours",
                (SELECT count(*) FROM "email_message"
                    WHERE {$messageFilter} AND "created_at" > unix_time() - 604800) AS "last_7_days",
                (SELECT count(*) FROM "email_message" WHERE {$messageFilter} AND "status" = 'queued') AS "queued_now",
                (SELECT count(*) FROM "email_message"
                    WHERE {$messageFilter} AND "status" = 'failed' AND "created_at" > unix_time() - 86400)
                    AS "failed_last_24_hours",
                (SELECT count(*) FROM "rejected_request"
                    WHERE {$messageFilter} AND "created_at" > unix_time() - 3600) AS "rejected_last_hour",
                (SELECT count(*) FROM "rejected_request"
                    WHERE {$messageFilter} AND "created_at" > unix_time() - 86400) AS "rejected_last_24_hours",
                (SELECT count(*) FROM "rejected_request"
                    WHERE {$messageFilter} AND "created_at" > unix_time() - 604800) AS "rejected_last_7_days",
                (SELECT count(*) FROM "rejected_request"
                    WHERE {$messageFilter} AND {$byService} AND "created_at" > unix_time() - 86400)
                    AS "rejected_by_service_last_24_hours",
                (SELECT count(*) FROM "rejected_request"
                    WHERE {$messageFilter} AND {$byProvider} AND "created_at" > unix_time() - 86400)
                    AS "refused_while_waiting_last_24_hours",
                (SELECT count(*) FROM "email_message" AS "m"
                    WHERE {$eventFilter} AND {$failedAfterQueueing} AND "m"."created_at" > unix_time() - 86400)
                    AS "failed_after_queueing_last_24_hours",
                (SELECT count(*) FROM "email_message" AS "m" WHERE {$eventFilter} AND {$retrying}) AS "retrying_now",
                "e".*
            FROM (
                SELECT
                    count(*) FILTER (WHERE "event_type" = 'delivered') AS "delivered_last_7_days",
                    count(*) FILTER (WHERE "event_type" = 'bounced') AS "bounced_last_7_days",
                    count(*) FILTER (WHERE "event_type" = 'opened') AS "opened_last_7_days",
                    count(*) FILTER (WHERE "event_type" = 'clicked') AS "clicked_last_7_days",
                    count(*) FILTER (WHERE "event_type" = 'spam_report') AS "spam_reports_last_7_days"
                FROM "email_event" AS "ev"
                LEFT JOIN "email_message" AS "m" ON "m"."id" = "ev"."email_message_id"
                WHERE {$eventFilter} AND "ev"."occurred_at" > unix_time() - 604800
            ) AS "e"
            SQL;

        return new ActivityCounts(pg_fetch_all(ServiceFactory::getDb()->query($query))[0]);
    }


    /**
     * Get how many emails were accepted in each of the last 24 hours, oldest first.
     *
     * @param string $emailTokenId - the token's ID, or an empty string for every token.
     *
     * @return HourlyVolumeCollection - 24 entries, including the current (partial) hour.
     */
    public static function getHourlyVolume(string $emailTokenId = '') : HourlyVolumeCollection
    {
        $escapedId = ServiceFactory::getDb()->escapeValue($emailTokenId);
        $tokenFilter = ($emailTokenId === '') ? '' : ' AND "m"."email_token_id" = ' . $escapedId;

        $query = <<<SQL
            SELECT
                "h"."hour_start",
                count("m"."id") AS "accepted",
                count("m"."id") FILTER (WHERE "m"."was_queued") AS "queued"
            FROM generate_series(
                (unix_time() / 3600) * 3600 - 82800,
                (unix_time() / 3600) * 3600,
                3600
            ) AS "h"("hour_start")
            LEFT JOIN "email_message" AS "m"
                ON "m"."created_at" >= "h"."hour_start"
                AND "m"."created_at" < "h"."hour_start" + 3600{$tokenFilter}
            GROUP BY "h"."hour_start"
            ORDER BY "h"."hour_start"
            SQL;

        $volumes = [];

        foreach (pg_fetch_all(ServiceFactory::getDb()->query($query)) as $row)
        {
            $hourStart = Time::toDateTime((int)$row['hour_start']);
            $volumes[] = new HourlyVolume($hourStart, (int)$row['accepted'], (int)$row['queued']);
        }

        return new HourlyVolumeCollection(...$volumes);
    }


    /**
     * Load the most recent emails that a filter shows, newest first.
     *
     * @param MessageFilter $filter - which emails.
     *
     * @param int $limit - the most to load, from 1 to 500.
     *
     * @return EmailMessageCollection
     */
    public static function loadMessages(MessageFilter $filter, int $limit) : EmailMessageCollection
    {
        $conditions = [];

        if ($filter->hasEmailToken())
        {
            $escapedId = ServiceFactory::getDb()->escapeValue($filter->getEmailTokenId());
            $conditions[] = '"m"."email_token_id" = ' . $escapedId;
        }

        if (count($filter->getStatuses()) > 0)
        {
            $escaped = [];

            foreach ($filter->getStatuses() as $status)
            {
                $escaped[] = ServiceFactory::getDb()->escapeValue($status->value);
            }

            $conditions[] = '"m"."status" IN (' . implode(', ', $escaped) . ')';
        }

        if (count($filter->getEvents()) > 0)
        {
            // The window is of when the event happened, as the dashboard's counts of events are.
            $types = [];

            foreach ($filter->getEvents() as $type)
            {
                $types[] = ServiceFactory::getDb()->escapeValue($type->value);
            }

            $conditions[] =
                'EXISTS (SELECT 1 FROM "email_event" AS "ev" WHERE "ev"."email_message_id" = "m"."id"'
                . ' AND "ev"."event_type" IN (' . implode(', ', $types) . ')'
                . ' AND ' . $filter->getWindow()->getSqlCondition('"ev"."occurred_at"') . ')';
        }
        else
        {
            $conditions[] = $filter->getWindow()->getSqlCondition('"m"."created_at"');
        }

        $conditions[] = $filter->getProgress()->getSqlCondition();
        $limit = max(1, min(500, $limit));
        $where = implode(' AND ', $conditions);
        $query =
            "SELECT \"m\".\"id\" FROM \"email_message\" AS \"m\" WHERE {$where}"
            . " ORDER BY \"m\".\"created_at\" DESC, \"m\".\"id\" DESC LIMIT {$limit}";

        $messages = [];

        foreach (pg_fetch_all(ServiceFactory::getDb()->query($query)) as $row)
        {
            $messages[] = EmailMessageTable::getInstance()->load($row['id'], CacheMode::IGNORE_CACHE);
        }

        return new EmailMessageCollection(...$messages);
    }


    /**
     * Load the tracking events for an email, oldest first.
     *
     * @param EmailMessage $message - the email.
     *
     * @return EmailEventCollection
     */
    public static function loadEvents(EmailMessage $message) : EmailEventCollection
    {
        $wherePairs = ['email_message_id' => $message->getId()];
        $events = EmailEventTable::getInstance()->loadWhereAnd($wherePairs, CacheMode::IGNORE_CACHE);

        $compareOldestFirst = function (EmailEvent $a, EmailEvent $b) : int {
            $order = $a->getOccurredAt() <=> $b->getOccurredAt();
            return ($order !== 0) ? $order : strcmp((string)$a->getId(), (string)$b->getId());
        };

        usort($events, $compareOldestFirst);
        return new EmailEventCollection(...$events);
    }


    /**
     * Load each attempt at handing an email to a provider, oldest first.
     *
     * @param EmailMessage $message - the email.
     *
     * @return EmailAttemptCollection
     */
    public static function loadAttempts(EmailMessage $message) : EmailAttemptCollection
    {
        $id = ServiceFactory::getDb()->escapeValue($message->getId());
        $attempts = [];

        $query =
            'SELECT "provider", "dispatched_at", "finished_at", "outcome",'
            . " coalesce(\"error\", '') AS \"error\" FROM \"email_attempt\" WHERE \"email_message_id\" = {$id}"
            . ' ORDER BY "dispatched_at", "id"';

        foreach (pg_fetch_all(ServiceFactory::getDb()->query($query)) as $row)
        {
            $outcome = AttemptOutcome::from($row['outcome']);
            $finishedAt = ($row['finished_at'] === null) ? null : (float)$row['finished_at'];

            $attempts[] = new EmailAttempt(
                $row['provider'],
                (float)$row['dispatched_at'],
                $finishedAt,
                $outcome,
                $row['error']
            );
        }

        return new EmailAttemptCollection(...$attempts);
    }


    /**
     * Load the most recent rejected requests, newest first.
     *
     * @param int $limit - the most to load, from 1 to 500.
     *
     * @param string $emailTokenId - only load those for this email token, or an empty string for any (or none).
     *
     * @param TimeWindow $window - only load those within this window.
     *
     * @param RejectionOrigin $origin - only load those turned away by this service, or by a provider (or both).
     *
     * @return RejectedRequestCollection
     */
    public static function loadRejections(
        int $limit,
        string $emailTokenId,
        TimeWindow $window,
        RejectionOrigin $origin
    ) : RejectedRequestCollection
    {
        $limit = max(1, min(500, $limit));
        $rejections = [];
        $escapedId = ServiceFactory::getDb()->escapeValue($emailTokenId);
        $tokenCondition = ($emailTokenId === '') ? 'TRUE' : '"email_token_id" = ' . $escapedId;
        $windowCondition = $window->getSqlCondition('"created_at"');
        $where = "{$tokenCondition} AND {$origin->getSqlCondition()} AND {$windowCondition}";

        $query =
            "SELECT \"id\" FROM \"rejected_request\" WHERE {$where}"
            . " ORDER BY \"created_at\" DESC, \"id\" DESC LIMIT {$limit}";

        foreach (pg_fetch_all(ServiceFactory::getDb()->query($query)) as $row)
        {
            $rejections[] = RejectedRequestTable::getInstance()->load($row['id'], CacheMode::IGNORE_CACHE);
        }

        return new RejectedRequestCollection(...$rejections);
    }


    /**
     * Load the email tokens that have sent the most emails in a time window, most first, for the dashboard.
     *
     * @param TimeWindow $window - when the emails were accepted.
     *
     * @param int $limit - the most tokens to load.
     *
     * @return EmailTokenUsageCollection
     */
    public static function loadMostUsedEmailTokens(TimeWindow $window, int $limit) : EmailTokenUsageCollection
    {
        $limit = max(1, min(100, $limit));
        $usages = [];
        $where = '"email_token_id" IS NOT NULL AND ' . $window->getSqlCondition('"created_at"');

        $query =
            "SELECT \"email_token_id\", count(*) AS \"email_count\" FROM \"email_message\" WHERE {$where}"
            . " GROUP BY \"email_token_id\" ORDER BY \"email_count\" DESC, \"email_token_id\" LIMIT {$limit}";

        foreach (pg_fetch_all(ServiceFactory::getDb()->query($query)) as $row)
        {
            $emailToken = EmailTokenRepository::load($row['email_token_id']);
            $usages[] = new EmailTokenUsage($emailToken, (int)$row['email_count']);
        }

        return new EmailTokenUsageCollection(...$usages);
    }


    /**
     * Load one email.
     *
     * @throws NotFoundException - if there's no such email.
     */
    public static function loadMessage(string $id) : EmailMessage
    {
        try
        {
            if (PgsqlLib::isValidUuid($id) === false)
            {
                throw new NotFoundException("There is no email with the ID \"{$id}\".");
            }

            /* @var $message EmailMessage */
            $message = EmailMessageTable::getInstance()->load($id, CacheMode::IGNORE_CACHE);
        }
        catch (\Programster\PgsqlObjects\Exceptions\ExceptionNoSuchIdException)
        {
            throw new NotFoundException("There is no email with the ID \"{$id}\".");
        }

        return $message;
    }
}
