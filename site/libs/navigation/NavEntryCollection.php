<?php

declare(strict_types = 1);

use Programster\Collections\AbstractReadonlyCollection;


/**
 * @extends AbstractReadonlyCollection<NavEntry>
 */
final readonly class NavEntryCollection extends AbstractReadonlyCollection
{
    public function __construct(NavEntry ...$entries)
    {
        parent::__construct(...$entries);
    }
}
