<?php

declare(strict_types = 1);

use Programster\Collections\AbstractReadonlyCollection;


/**
 * @extends AbstractReadonlyCollection<ProviderUsageWindow>
 */
final readonly class ProviderUsageWindowCollection extends AbstractReadonlyCollection
{
    public function __construct(ProviderUsageWindow ...$windows)
    {
        parent::__construct(...$windows);
    }
}
