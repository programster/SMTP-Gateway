<?php

declare(strict_types = 1);

use Programster\Collections\AbstractReadonlyCollection;


/**
 * @extends AbstractReadonlyCollection<Suite>
 */
final readonly class SuiteCollection extends AbstractReadonlyCollection
{
    public function __construct(Suite ...$suites)
    {
        parent::__construct(...$suites);
    }
}
