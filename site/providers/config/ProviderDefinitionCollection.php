<?php

declare(strict_types = 1);

use Programster\Collections\AbstractReadonlyCollection;


/**
 * @extends AbstractReadonlyCollection<ProviderDefinition>
 */
final readonly class ProviderDefinitionCollection extends AbstractReadonlyCollection
{
    public function __construct(ProviderDefinition ...$providerDefinitions)
    {
        parent::__construct(...$providerDefinitions);
    }
}
