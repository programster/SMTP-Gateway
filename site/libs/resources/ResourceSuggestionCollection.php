<?php

declare(strict_types = 1);

use Programster\Collections\AbstractReadonlyCollection;


/**
 * @extends AbstractReadonlyCollection<ResourceSuggestion>
 */
final readonly class ResourceSuggestionCollection extends AbstractReadonlyCollection
{
    public function __construct(ResourceSuggestion ...$suggestions)
    {
        parent::__construct(...$suggestions);
    }
}
