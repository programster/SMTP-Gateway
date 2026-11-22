<?php

/*
 * How much is queued ahead of an email (in the same rate limit scope), which will use any room under the limits
 * first: how many emails, and how many recipients they have between them.
 */

declare(strict_types = 1);


readonly class QueuedDemand
{
    private int $m_emails;
    private int $m_recipients;


    public function __construct(int $emails, int $recipients)
    {
        $this->m_emails = $emails;
        $this->m_recipients = $recipients;
    }


    public static function none() : QueuedDemand
    {
        return new QueuedDemand(0, 0);
    }


    /**
     * Count what's queued ahead of an email (due now, and with a higher priority, or the same priority and queued
     * before it) within a scope.
     *
     * @param string $messageId - the email's ID.
     *
     * @param Priority $priority - its priority.
     *
     * @param RateLimitScope $scope - the scope (e.g. its token, or everything).
     *
     * @return QueuedDemand
     */
    public static function countAhead(string $messageId, Priority $priority, RateLimitScope $scope) : QueuedDemand
    {
        $row = pg_fetch_all(ServiceFactory::getDb()->query(
            'SELECT count(*) AS "emails", coalesce(sum("recipient_count"), 0) AS "recipients" FROM "email_message"'
            . ' WHERE ' . self::getAheadSql($messageId, $priority) . " AND {$scope->getSqlCondition()}"
        ))[0];

        return new QueuedDemand((int)$row['emails'], (int)$row['recipients']);
    }


    /**
     * Count what's queued ahead of an email (see getAheadSql()) that can actually be sent before it, across
     * every token, for the limits shared between tokens (the global and per-provider ones) and the workers' time. All
     * of its own token's emails ahead of it go first, as a token's emails are sent in order. But another token's only
     * count as far as that token's own limits let them out now: a token that has hit its limit, with a backlog behind
     * it, isn't going to send its backlog first, so mustn't make every other token's emails look slow.
     *
     * @param string $messageId - the email's ID.
     *
     * @param string $emailTokenId - the token it was sent with.
     *
     * @param Priority $priority - its priority. Only emails with a higher one, or the same one and queued before it,
     * are ahead of it.
     *
     * @return QueuedDemand
     */
    public static function countAheadThatCanBeSent(
        string $messageId,
        string $emailTokenId,
        Priority $priority
    ) : QueuedDemand
    {
        $rows = pg_fetch_all(ServiceFactory::getDb()->query(
            'SELECT "email_token_id", count(*) AS "emails", coalesce(sum("recipient_count"), 0) AS "recipients"'
            . ' FROM "email_message" WHERE ' . self::getAheadSql($messageId, $priority) . ' GROUP BY "email_token_id"'
        ));

        $emails = 0;
        $recipients = 0;

        foreach ($rows as $row)
        {
            $queuedEmails = (int)$row['emails'];
            $queuedRecipients = (int)$row['recipients'];

            // Its own token's emails all go first, and the service's own emails have no token limits to hold them back.
            if ($row['email_token_id'] === $emailTokenId || $row['email_token_id'] === null)
            {
                $emails += $queuedEmails;
                $recipients += $queuedRecipients;
            }
            else
            {
                $limits = EmailTokenRepository::loadRateLimits($row['email_token_id']);
                $room = RateLimiter::getRoom($limits, RateLimitScope::forEmailToken($row['email_token_id']));
                $sendable = min($queuedEmails, $room->getEmails());

                // The recipients of the emails it can send, roughly: as many as their share of its queue has.
                $share = intdiv($queuedRecipients * $sendable, $queuedEmails);
                $emails += $sendable;
                $recipients += min($share, $room->getRecipients());
            }
        }

        return new QueuedDemand($emails, $recipients);
    }


    /**
     * Get the SQL condition picking out the emails queued ahead of one: due now, and with a higher priority, or the
     * same priority and queued before it.
     */
    private static function getAheadSql(string $messageId, Priority $priority) : string
    {
        $id = ServiceFactory::getDb()->escapeValue($messageId);
        $value = $priority->getValue();

        return
            "\"status\" = 'queued' AND \"next_attempt_at\" <= unix_time()"
            . " AND (\"priority\" > {$value} OR (\"priority\" = {$value} AND \"id\" < {$id}))";
    }


    # Accessors
    public function getEmails() : int { return $this->m_emails; }
    public function getRecipients() : int { return $this->m_recipients; }
}
