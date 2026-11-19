<?php

declare(strict_types = 1);

use Programster\Collections\AbstractReadonlyCollection;


/**
 * @extends AbstractReadonlyCollection<LogCategory>
 */
final readonly class LogCategoryCollection extends AbstractReadonlyCollection
{
    public function __construct(LogCategory ...$categories)
    {
        parent::__construct(...$categories);
    }
}
