<?php

declare(strict_types = 1);

use Programster\Collections\AbstractReadonlyCollection;
use Programster\Log\LogLevel;


/**
 * @extends AbstractReadonlyCollection<LogLevel>
 */
final readonly class LogLevelCollection extends AbstractReadonlyCollection
{
    public function __construct(LogLevel ...$levels)
    {
        parent::__construct(...$levels);
    }


    /**
     * Create a collection of every level, from the least severe (debug, 0) to the most (emergency, 7).
     * @return LogLevelCollection
     */
    public static function createAllFromLeastSevere() : LogLevelCollection
    {
        return new LogLevelCollection(...array_map([LogLevel::class, 'createFromInt'], range(0, 7)));
    }
}
