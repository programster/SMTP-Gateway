<?php

/*
 * Records the tracking events a provider reports through its webhook against the emails they're about. A provider's
 * webhook can report events for email that this service didn't send (e.g. SendGrid's is for its whole account, so it
 * reports every email sent with any of its API keys, by any other system too), and those are ignored: only an event
 * for one of this service's own emails is recorded. So nothing about another system's email (e.g. who it was sent
 * to) is ever kept, and the statistics only count this service's.
 */

declare(strict_types = 1);

use Programster\PgsqlLib\PgsqlLib;


final class EventRecorder
{
    /**
     * Record events, ignoring any that aren't about one of this service's emails (see the class's comment). An event
     * the provider has already reported (the same event ID) is ignored too, as providers retry webhooks that they
     * think failed.
     *
     * @param string $providerName - the name (in settings.yaml) of the provider that reported them.
     *
     * @param ProviderEventCollection $events - the events.
     *
     * @return int - how many new events were recorded.
     */
    public static function record(string $providerName, ProviderEventCollection $events) : int
    {
        $recorded = 0;

        foreach ($events as $event)
        {
            /* @var $event ProviderEvent */
            if (PgsqlLib::isValidUuid($event->getMessageId()) || $event->getProviderMessageId() !== '')
            {
                $recorded += self::recordForMessage($providerName, $event);
            }
        }

        return $recorded;
    }


    /**
     * Record one event, if the email it's about is one of this service's (and still kept).
     *
     * @param string $providerName - the provider that reported it.
     *
     * @param ProviderEvent $event - the event, with a valid UUID as its message ID, or the provider's ID for the email,
     * which only matches an email sent through this provider.
     *
     * @return int - 1 if it was recorded, or 0 if it's about an email this service doesn't have, or was recorded
     * before.
     */
    private static function recordForMessage(string $providerName, ProviderEvent $event) : int
    {
        $values = [
            ServiceFactory::getDb()->escapeValue((string)EmailEventTable::getInstance()->generateId()),
            '"m"."id"',
            ServiceFactory::getDb()->escapeValue($providerName),
            'NULLIF(' . ServiceFactory::getDb()->escapeValue($event->getProviderEventId()) . ", '')",
            ServiceFactory::getDb()->escapeValue($event->getType()->value),
            'NULLIF(' . ServiceFactory::getDb()->escapeValue(mb_substr($event->getRecipient(), 0, 320)) . ", '')",
            'NULLIF(' . ServiceFactory::getDb()->escapeValue(mb_substr($event->getDetail(), 0, 2000)) . ", '')",
            $event->getOccurredAt()->getTimestamp(),
        ];

        $matchesEmail = PgsqlLib::isValidUuid($event->getMessageId())
            ? '"m"."id" = ' . ServiceFactory::getDb()->escapeValue($event->getMessageId())
            : '"m"."provider" = ' . ServiceFactory::getDb()->escapeValue($providerName)
                . ' AND "m"."provider_message_id" = '
                . ServiceFactory::getDb()->escapeValue($event->getProviderMessageId());

        // Selected from the email it's about, so an event for an email that isn't there inserts nothing.
        $result = ServiceFactory::getDb()->query(
            'INSERT INTO "email_event" ("id", "email_message_id", "provider", "provider_event_id", "event_type",'
            . ' "recipient", "detail", "occurred_at")'
            . ' SELECT ' . implode(', ', $values) . ' FROM "email_message" AS "m"'
            . " WHERE {$matchesEmail} LIMIT 1"
            . ' ON CONFLICT ("provider", "provider_event_id") DO NOTHING'
        );

        return pg_affected_rows($result);
    }
}
