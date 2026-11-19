<?php

/*
 * How serious the outcome of a sender check is (see SenderCheck), from fine to a problem that stops email being
 * delivered.
 */

declare(strict_types = 1);


enum SenderCheckLevel : string
{
    // Set up as it should be.
    case OK = 'ok';

    // Worth knowing, or improving, but email will still be delivered.
    case INFO = 'info';

    // Email will probably be sent, but some of it may go to spam, or be rejected by stricter receivers.
    case WARNING = 'warning';

    // Email will be rejected, or something that's needed doesn't work.
    case ERROR = 'error';


    /**
     * Get how serious it is, as a number that's higher the worse it is, for finding the worst of several.
     *
     * @return int
     */
    public function getSeverity() : int
    {
        return match ($this) {
            SenderCheckLevel::OK => 0,
            SenderCheckLevel::INFO => 1,
            SenderCheckLevel::WARNING => 2,
            SenderCheckLevel::ERROR => 3,
        };
    }
}
