<?php

declare(strict_types = 1);

use Programster\Collections\AbstractReadonlyCollection;


/**
 * @extends AbstractReadonlyCollection<LogLevel>
 */
final readonly class LogLevelCollection extends AbstractReadonlyCollection
{
    public function __construct(LogLevel ...$levels)
    {
        parent::__construct(...$levels);
    }
}
