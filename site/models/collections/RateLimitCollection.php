<?php

declare(strict_types = 1);

use Programster\Collections\AbstractReadonlyCollection;


/**
 * @extends AbstractReadonlyCollection<RateLimit>
 */
final readonly class RateLimitCollection extends AbstractReadonlyCollection
{
    public function __construct(RateLimit ...$rateLimits)
    {
        parent::__construct(...$rateLimits);
    }
}
