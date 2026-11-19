<?php

declare(strict_types = 1);

use Programster\Collections\AbstractReadonlyCollection;


/**
 * @extends AbstractReadonlyCollection<ProviderEvent>
 */
final readonly class ProviderEventCollection extends AbstractReadonlyCollection
{
    public function __construct(ProviderEvent ...$providerEvents)
    {
        parent::__construct(...$providerEvents);
    }
}
