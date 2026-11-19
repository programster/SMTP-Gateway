<?php

declare(strict_types = 1);

use Programster\Collections\AbstractReadonlyCollection;


/**
 * @extends AbstractReadonlyCollection<AddressRule>
 */
final readonly class AddressRuleCollection extends AbstractReadonlyCollection
{
    public function __construct(AddressRule ...$addressRules)
    {
        parent::__construct(...$addressRules);
    }
}
