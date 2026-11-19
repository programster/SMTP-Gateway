<?php

declare(strict_types = 1);

use Programster\Collections\AbstractReadonlyCollection;


/**
 * @extends AbstractReadonlyCollection<EmailTokenUsage>
 */
final readonly class EmailTokenUsageCollection extends AbstractReadonlyCollection
{
    public function __construct(EmailTokenUsage ...$usages)
    {
        parent::__construct(...$usages);
    }
}
