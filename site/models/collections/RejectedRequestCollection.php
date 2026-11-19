<?php

declare(strict_types = 1);

use Programster\Collections\AbstractReadonlyCollection;


/**
 * @extends AbstractReadonlyCollection<RejectedRequest>
 */
final readonly class RejectedRequestCollection extends AbstractReadonlyCollection
{
    public function __construct(RejectedRequest ...$rejectedRequests)
    {
        parent::__construct(...$rejectedRequests);
    }
}
