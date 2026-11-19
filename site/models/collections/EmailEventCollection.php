<?php

declare(strict_types = 1);

use Programster\Collections\AbstractReadonlyCollection;


/**
 * @extends AbstractReadonlyCollection<EmailEvent>
 */
final readonly class EmailEventCollection extends AbstractReadonlyCollection
{
    public function __construct(EmailEvent ...$emailEvents)
    {
        parent::__construct(...$emailEvents);
    }
}
