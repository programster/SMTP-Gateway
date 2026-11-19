<?php

declare(strict_types = 1);

use Programster\Collections\AbstractReadonlyCollection;


/**
 * @extends AbstractReadonlyCollection<SenderCheckerInterface>
 */
final readonly class SenderCheckerCollection extends AbstractReadonlyCollection
{
    public function __construct(SenderCheckerInterface ...$senderCheckerInterfaces)
    {
        parent::__construct(...$senderCheckerInterfaces);
    }
}
