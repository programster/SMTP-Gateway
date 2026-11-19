<?php

/*
 * Which emails a list shows (see Stats::loadMessages()): sent with one email token, with one of some statuses, within
 * a time window, with one of some events reported by the provider, and where an unsent one has got to (see
 * MessageProgress). Each is "any" until it's narrowed, with a
 * with...() method, which returns a copy. With events, the window is of when the event happened (as the dashboard's
 * event counts are), rather than when the email was accepted.
 */

declare(strict_types = 1);


final readonly class MessageFilter
{
    private string $m_emailTokenId;
    private MessageStatusCollection $m_statuses;
    private TimeWindow $m_window;
    private EmailEventTypeCollection $m_events;
    private MessageProgress $m_progress;


    private function __construct(
        string $emailTokenId,
        MessageStatusCollection $statuses,
        TimeWindow $window,
        EmailEventTypeCollection $events,
        MessageProgress $progress
    )
    {
        $this->m_emailTokenId = $emailTokenId;
        $this->m_statuses = $statuses;
        $this->m_window = $window;
        $this->m_events = $events;
        $this->m_progress = $progress;
    }


    /**
     * Create a filter of every email: any token, status and time, with or without events.
     * @return MessageFilter
     */
    public static function createForEverything() : MessageFilter
    {
        $noStatuses = new MessageStatusCollection();
        $noEvents = new EmailEventTypeCollection();
        return new MessageFilter('', $noStatuses, TimeWindow::ANY_TIME, $noEvents, MessageProgress::ANY);
    }


    /**
     * Get a copy that only shows emails sent with one email token.
     *
     * @param string $emailTokenId - the token's ID.
     *
     * @return MessageFilter
     *
     * @throws InvalidInputException - if it isn't an email token's ID.
     */
    public function withEmailToken(string $emailTokenId) : MessageFilter
    {
        if (TokenKind::EMAIL_TOKEN->isId($emailTokenId) === false)
        {
            throw new InvalidInputException("\"{$emailTokenId}\" isn't an email token's ID.");
        }

        return new MessageFilter($emailTokenId, $this->m_statuses, $this->m_window, $this->m_events, $this->m_progress);
    }


    /**
     * Get a copy that only shows emails with one of these statuses.
     * @return MessageFilter
     */
    public function withStatuses(MessageStatus ...$statuses) : MessageFilter
    {
        $collection = new MessageStatusCollection(...$statuses);
        $events = $this->m_events;
        return new MessageFilter($this->m_emailTokenId, $collection, $this->m_window, $events, $this->m_progress);
    }


    /**
     * Get a copy that only shows emails accepted within a window (or, with events, whose event was within it).
     * @return MessageFilter
     */
    public function withWindow(TimeWindow $window) : MessageFilter
    {
        $events = $this->m_events;
        return new MessageFilter($this->m_emailTokenId, $this->m_statuses, $window, $events, $this->m_progress);
    }


    /**
     * Get a copy that only shows emails the provider reported one of these events for.
     * @return MessageFilter
     */
    public function withEvents(EmailEventType ...$events) : MessageFilter
    {
        $collection = new EmailEventTypeCollection(...$events);
        $window = $this->m_window;
        return new MessageFilter($this->m_emailTokenId, $this->m_statuses, $window, $collection, $this->m_progress);
    }


    /**
     * Get a copy that only shows emails that have got to one point (see MessageProgress).
     * @param MessageProgress $progress - the point.
     * @return MessageFilter
     */
    public function withProgress(MessageProgress $progress) : MessageFilter
    {
        $id = $this->m_emailTokenId;
        return new MessageFilter($id, $this->m_statuses, $this->m_window, $this->m_events, $progress);
    }


    public function hasEmailToken() : bool
    {
        return $this->m_emailTokenId !== '';
    }


    # Accessors
    public function getEmailTokenId() : string { return $this->m_emailTokenId; }
    public function getStatuses() : MessageStatusCollection { return $this->m_statuses; }
    public function getWindow() : TimeWindow { return $this->m_window; }
    public function getEvents() : EmailEventTypeCollection { return $this->m_events; }
    public function getProgress() : MessageProgress { return $this->m_progress; }
}
