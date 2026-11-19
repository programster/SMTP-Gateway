<?php

declare(strict_types = 1);

use Programster\Collections\AbstractReadonlyCollection;


/**
 * @extends AbstractReadonlyCollection<ProviderField>
 */
final readonly class ProviderFieldCollection extends AbstractReadonlyCollection
{
    public function __construct(ProviderField ...$fields)
    {
        parent::__construct(...$fields);
    }
}
