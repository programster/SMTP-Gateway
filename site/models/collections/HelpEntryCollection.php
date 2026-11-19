<?php

declare(strict_types = 1);

use Programster\Collections\AbstractReadonlyCollection;


/**
 * @extends AbstractReadonlyCollection<HelpEntry>
 */
final readonly class HelpEntryCollection extends AbstractReadonlyCollection
{
    public function __construct(HelpEntry ...$entries)
    {
        parent::__construct(...$entries);
    }
}
