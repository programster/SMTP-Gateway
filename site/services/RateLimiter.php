<?php

/*
 * Checks whether rate limits allow an email to be sent right now, and if not, how long until they will. Every email
 * handed to a provider counts, from the moment it's handed over (its dispatched_at time), across sliding windows: as
 * one email towards the limits on emails (requests to the provider), and as its number of recipients towards the
 * limits on recipients. A provider's limits count every attempt it was given, failed ones included (see
 * RateLimitScope).
 *
 * allows() must be called while holding the dispatch lock (see MessageDispatcher), so two processes can't both see
 * room for one more email and both send.
 */

declare(strict_types = 1);


final class RateLimiter
{
    /**
     * Check whether the limits have room for an email now.
     *
     * @param RateLimitSet $limits - the limits.
     *
     * @param RateLimitScope $scope - what they count across.
     *
     * @param int $recipientCount - the email's number of recipients.
     *
     * @return bool
     */
    public static function allows(RateLimitSet $limits, RateLimitScope $scope, int $recipientCount) : bool
    {
        return self::getSecondsUntilRoom($limits, $scope, $recipientCount, QueuedDemand::none()) <= 0.0;
    }


    /**
     * Work out how long until the limits will have room for an email, from when the emails already sent within each
     * window were sent (so when they'll drop out of it), and how much is queued ahead of it (which will use the room
     * first).
     *
     * @param RateLimitSet $limits - the limits.
     *
     * @param RateLimitScope $scope - what they count across.
     *
     * @param int $recipientCount - the email's number of recipients.
     *
     * @param QueuedDemand $ahead - what's queued ahead of the email within the scope (QueuedDemand::none() when it's
     * the email being claimed now, so nothing is ahead of it).
     *
     * @return float - seconds from now: 0 if there's room now, or INF if there never will be (it has more recipients
     * than a recipient limit allows).
     */
    public static function getSecondsUntilRoom(
        RateLimitSet $limits,
        RateLimitScope $scope,
        int $recipientCount,
        QueuedDemand $ahead
    ) : float
    {
        $wait = 0.0;

        if ($limits->isEmpty() === false)
        {
            $counts = self::countWithinWindows($limits, $scope);

            foreach ($limits->getLimits() as $index => $limit)
            {
                /* @var $limit RateLimit */
                $isRecipients = ($limit->getUnit() === RateLimitUnit::RECIPIENTS);
                $adding = $isRecipients ? $recipientCount : 1;
                $queuedAhead = $isRecipients ? $ahead->getRecipients() : $ahead->getEmails();
                $excess = $counts[$index] + $queuedAhead + $adding - $limit->getMax();

                if ($limit->canEverAllow($recipientCount) === false)
                {
                    $wait = INF;
                }
                elseif ($excess > 0 && $excess <= $counts[$index])
                {
                    // Room comes as soon as enough of what's in the window drops out of it.
                    $wait = max($wait, self::getSecondsUntilExpired($limit, $scope, $excess));
                }
                elseif ($excess > 0)
                {
                    // More is waiting than the window holds, so it takes further windows: once everything in this one
                    // has dropped out, each period lets the limit's maximum through.
                    $windowClears = ($counts[$index] > 0)
                        ? self::getSecondsUntilExpired($limit, $scope, $counts[$index])
                        : 0.0;

                    $furtherWindows = ceil(($excess - $counts[$index]) / $limit->getMax());
                    $wait = max($wait, $windowClears + $furtherWindows * $limit->getPeriodSeconds());
                }
            }
        }

        return $wait;
    }


    /**
     * Work out how much more the limits would let through right now, from what was sent within each window.
     *
     * @param RateLimitSet $limits - the limits.
     *
     * @param RateLimitScope $scope - what they count across.
     *
     * @return RateLimitRoom - how many more emails and recipients (PHP_INT_MAX for those with no limit).
     */
    public static function getRoom(RateLimitSet $limits, RateLimitScope $scope) : RateLimitRoom
    {
        $emails = PHP_INT_MAX;
        $recipients = PHP_INT_MAX;

        if ($limits->isEmpty() === false)
        {
            $counts = self::countWithinWindows($limits, $scope);

            foreach ($limits->getLimits() as $index => $limit)
            {
                /* @var $limit RateLimit */
                $left = $limit->getMax() - $counts[$index];

                if ($limit->getUnit() === RateLimitUnit::RECIPIENTS)
                {
                    $recipients = min($recipients, $left);
                }
                else
                {
                    $emails = min($emails, $left);
                }
            }
        }

        return new RateLimitRoom($emails, $recipients);
    }


    /**
     * Count what was sent within each limit's window (emails, or the sum of their recipients), in a single query.
     *
     * @return int[] - the count for each limit, in the same order as $limits->getLimits().
     */
    private static function countWithinWindows(RateLimitSet $limits, RateLimitScope $scope) : array
    {
        $columns = [];
        $longestPeriod = 0;

        foreach ($limits->getLimits() as $index => $limit)
        {
            /* @var $limit RateLimit */
            $seconds = $limit->getPeriodSeconds();
            $longestPeriod = max($longestPeriod, $seconds);
            $filter = "FILTER (WHERE \"dispatched_at\" > now() - make_interval(secs => {$seconds}))";
            $alias = "\"c{$index}\"";

            $columns[] = match ($limit->getUnit()) {
                RateLimitUnit::EMAILS => "count(*) {$filter} AS {$alias}",
                RateLimitUnit::RECIPIENTS => "coalesce(sum(\"recipient_count\") {$filter}, 0) AS {$alias}",
            };
        }

        $query =
            'SELECT ' . implode(', ', $columns) . " FROM {$scope->getTable()}"
            . " WHERE \"dispatched_at\" > now() - make_interval(secs => {$longestPeriod})"
            . " AND {$scope->getSqlCondition()}";

        $row = DB::fetchRows($query)[0];
        $counts = [];

        foreach (array_keys($limits->getLimits()->toArray()) as $index)
        {
            $counts[$index] = (int)$row["c{$index}"];
        }

        return $counts;
    }


    /**
     * Work out how long until enough of what was sent within a limit's window drops out of it to make room.
     *
     * @param RateLimit $limit - the limit.
     *
     * @param RateLimitScope $scope - what it counts across.
     *
     * @param int $excess - how many emails (or recipients) have to drop out of the window first.
     *
     * @return float - seconds from now.
     */
    private static function getSecondsUntilExpired(RateLimit $limit, RateLimitScope $scope, int $excess) : float
    {
        $seconds = $limit->getPeriodSeconds();
        $weight = ($limit->getUnit() === RateLimitUnit::RECIPIENTS) ? '"recipient_count"' : '1';

        // Add up what was sent, oldest first, and find the send that takes the total past the excess. Once that has
        // dropped out of the window, there's room.
        $query =
            "SELECT extract(epoch FROM (\"dispatched_at\" + make_interval(secs => {$seconds}) - now())) AS \"wait\""
            . " FROM (SELECT \"dispatched_at\","
            . " sum({$weight}) OVER (ORDER BY \"dispatched_at\", \"id\") AS \"running_total\""
            . " FROM {$scope->getTable()} WHERE \"dispatched_at\" > now() - make_interval(secs => {$seconds})"
            . " AND {$scope->getSqlCondition()}) AS \"sent\""
            . " WHERE \"running_total\" >= {$excess} ORDER BY \"dispatched_at\" LIMIT 1";

        $rows = DB::fetchRows($query);
        return (count($rows) > 0) ? max(0.0, (float)$rows[0]['wait']) : 0.0;
    }
}
