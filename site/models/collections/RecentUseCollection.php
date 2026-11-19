<?php

declare(strict_types = 1);

use Programster\Collections\AbstractReadonlyCollection;


/**
 * @extends AbstractReadonlyCollection<RecentUse>
 */
final readonly class RecentUseCollection extends AbstractReadonlyCollection
{
    public function __construct(RecentUse ...$uses)
    {
        parent::__construct(...$uses);
    }
}
