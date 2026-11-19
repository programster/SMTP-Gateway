<?php

declare(strict_types = 1);

use Programster\Collections\AbstractReadonlyCollection;


/**
 * @extends AbstractReadonlyCollection<\Programster\Swagger\Schemas\Property>
 */
final readonly class PropertyCollection extends AbstractReadonlyCollection
{
    public function __construct(\Programster\Swagger\Schemas\Property ...$properties)
    {
        parent::__construct(...$properties);
    }
}
