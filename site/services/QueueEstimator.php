<?php

/*
 * Estimates how long until a worker would start trying to send a newly queued email, so the SMTP or API connection
 * that sent it knows whether waiting for the outcome is worthwhile (see MessageDispatcher::submit()).
 *
 * It's the longer of:
 *  - how long until every rate limit that applies has room for it (its token's, the global ones, and a provider's),
 *    worked out from when the emails already in each window were sent, and
 *  - how long the emails ahead of it in the queue will take the workers to get through, at their recent speed.
 *
 * Only the emails ahead of it that can be sent first count: another token's backlog, held back by its own limits,
 * doesn't.
 */

declare(strict_types = 1);


final class QueueEstimator
{
    // What to assume one send takes, before there's any history to go on.
    private const float DEFAULT_SEND_SECONDS = 1.0;

    // The most one send is assumed to take, however slow recent ones were (e.g. while a provider was timing out).
    private const float MAX_SEND_SECONDS = 30.0;


    /**
     * Estimate how long until a worker would start trying to send an email.
     *
     * @param AuthenticatedEmailToken $emailToken - the email token it was sent with.
     *
     * @param string $messageId - the email's ID (it must already be queued).
     *
     * @param int $recipientCount - its number of recipients.
     *
     * @param EmailAddressCollection $senders - every address it's sent as (see RawEmail::getSenderAddresses()), as
     * only the providers that take them all can send it.
     *
     * @return float - seconds from now, or INF if it can't be sent until something changes (e.g. every provider is
     * unavailable, or none of the providers its token is restricted to is enabled).
     *
     * @throws ConfigurationException - if settings.yaml isn't valid.
     */
    public static function estimateSecondsUntilAttempt(
        AuthenticatedEmailToken $emailToken,
        string $messageId,
        int $recipientCount,
        EmailAddressCollection $senders
    ) : float
    {
        $tokenLimits = $emailToken->getPolicy()->getRateLimits();
        $tokenScope = RateLimitScope::forEmailToken($emailToken->getEmailToken()->getId());
        $globalScope = RateLimitScope::global();
        $globalLimits = GlobalRateLimitRepository::load();
        $priority = $emailToken->getPolicy()->getPriority();
        $aheadForKey = QueuedDemand::countAhead($messageId, $priority, $tokenScope);
        $tokenId = $emailToken->getEmailToken()->getId();
        $aheadOverall = QueuedDemand::countAheadThatCanBeSent($messageId, $tokenId, $priority);
        $config = ProviderRegistry::getConfig();
        $eligibility = new ProviderEligibility($emailToken->getPolicy()->getAllowedProviders(), $senders);

        $limitsWait = max(
            RateLimiter::getSecondsUntilRoom($tokenLimits, $tokenScope, $recipientCount, $aheadForKey),
            RateLimiter::getSecondsUntilRoom($globalLimits, $globalScope, $recipientCount, $aheadOverall),
            ProviderSelector::getSecondsUntilAProviderHasRoom($config, $eligibility, $recipientCount, $aheadOverall)
        );

        // A worker may have claimed the email while this was being worked out, in which case the limits above
        // counted it as already sent (and so could make it look minutes away), when it's actually being sent now.
        // This is checked after them, as a worker marks the email claimed in the same transaction that makes it
        // count, so if they counted it, this sees it claimed.
        $isClaimed = pg_fetch_result(ServiceFactory::getDb()->query(
            'SELECT "status" <> \'queued\' FROM "email_message" WHERE "id" = '
            . ServiceFactory::getDb()->escapeValue($messageId)
        ), 0, 0) === 't';

        return $isClaimed ? 0.0 : max($limitsWait, self::estimateQueueSeconds($aheadOverall));
    }


    /**
     * Estimate how long the workers will take to get through the emails ahead of this one: those being sent now,
     * and those queued before it that can be sent first. With W workers, the first W emails ahead don't hold it up
     * at all.
     *
     * @param QueuedDemand $queuedAhead - what's queued ahead of it that can be sent first (see
     * QueuedDemand::countAheadThatCanBeSent()).
     *
     * @return float - seconds.
     */
    private static function estimateQueueSeconds(QueuedDemand $queuedAhead) : float
    {
        $sendingQuery = 'SELECT count(*) FROM "email_message" WHERE "status" = \'sending\'';
        $sending = (int)pg_fetch_result(ServiceFactory::getDb()->query($sendingQuery), 0, 0);
        $ahead = $sending + $queuedAhead->getEmails();

        // The median, rather than the mean, so one unusually slow send can't skew it.
        $medianSeconds = (float)pg_fetch_result(ServiceFactory::getDb()->query(
            'SELECT coalesce(percentile_cont(0.5) WITHIN GROUP'
            . ' (ORDER BY extract(epoch FROM ("sent_at" - "dispatched_at"))),'
            . ' ' . self::DEFAULT_SEND_SECONDS . ') FROM (SELECT "sent_at", "dispatched_at" FROM "email_message"'
            . ' WHERE "status" = \'sent\' AND "sent_at" > now() - interval \'10 minutes\''
            . ' ORDER BY "sent_at" DESC LIMIT 100) AS "recent"'
        ), 0, 0);

        $sendSeconds = min(self::MAX_SEND_SECONDS, max(0.01, $medianSeconds));
        return floor($ahead / Config::get()->getQueueWorkers()) * $sendSeconds;
    }
}
