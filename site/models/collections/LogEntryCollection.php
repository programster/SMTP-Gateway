<?php

declare(strict_types = 1);

use Programster\Collections\AbstractReadonlyCollection;


/**
 * @extends AbstractReadonlyCollection<LogEntry>
 */
final readonly class LogEntryCollection extends AbstractReadonlyCollection
{
    public function __construct(LogEntry ...$logEntries)
    {
        parent::__construct(...$logEntries);
    }
}
