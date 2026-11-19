<?php

declare(strict_types = 1);

use Programster\Collections\AbstractReadonlyCollection;


/**
 * @extends AbstractReadonlyCollection<ApiKey>
 */
final readonly class ApiKeyCollection extends AbstractReadonlyCollection
{
    public function __construct(ApiKey ...$apiKeys)
    {
        parent::__construct(...$apiKeys);
    }
}
