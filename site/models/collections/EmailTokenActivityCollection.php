<?php

declare(strict_types = 1);

use Programster\Collections\AbstractReadonlyCollection;


/**
 * @extends AbstractReadonlyCollection<EmailTokenActivity>
 */
final readonly class EmailTokenActivityCollection extends AbstractReadonlyCollection
{
    public function __construct(EmailTokenActivity ...$keyActivities)
    {
        parent::__construct(...$keyActivities);
    }
}
