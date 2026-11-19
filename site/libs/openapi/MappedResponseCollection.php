<?php

declare(strict_types = 1);

use Programster\Collections\AbstractReadonlyCollection;


/**
 * @extends AbstractReadonlyCollection<\Programster\Swagger\MappedResponse>
 */
final readonly class MappedResponseCollection extends AbstractReadonlyCollection
{
    public function __construct(\Programster\Swagger\MappedResponse ...$responses)
    {
        parent::__construct(...$responses);
    }
}
