<?php

/*
 * Records the tracking events a provider reports through its webhook against the emails they're about.
 */

declare(strict_types = 1);


final class EventRecorder
{
    /**
     * Record events. An event the provider has already reported (the same event ID) is ignored, as providers
     * retry webhooks that they think failed.
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
            $messageId = 'NULL';

            if (Uuid::isValid($event->getMessageId()))
            {
                $escapedId = DB::escape($event->getMessageId());
                $messageId = "(SELECT \"id\" FROM \"email_message\" WHERE \"id\" = {$escapedId})";
            }

            $values = [
                DB::escape((string)EmailEventTable::getInstance()->generateId()),
                $messageId,
                DB::escape($providerName),
                'NULLIF(' . DB::escape($event->getProviderEventId()) . ", '')",
                DB::escape($event->getType()->value),
                'NULLIF(' . DB::escape(mb_substr($event->getRecipient(), 0, 320)) . ", '')",
                'NULLIF(' . DB::escape(mb_substr($event->getDetail(), 0, 2000)) . ", '')",
                DB::escape($event->getOccurredAt()->format(DATE_ATOM)),
            ];

            $result = ServiceFactory::getDb()->query(
                'INSERT INTO "email_event" ("id", "email_message_id", "provider", "provider_event_id", "event_type",'
                . ' "recipient", "detail", "occurred_at") VALUES (' . implode(', ', $values) . ')'
                . ' ON CONFLICT ("provider", "provider_event_id") DO NOTHING'
            );

            $recorded += pg_affected_rows($result);
        }

        return $recorded;
    }
}
