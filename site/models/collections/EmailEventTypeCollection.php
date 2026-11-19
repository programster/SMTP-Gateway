<?php

declare(strict_types = 1);

use Programster\Collections\AbstractReadonlyCollection;


/**
 * @extends AbstractReadonlyCollection<EmailEventType>
 */
final readonly class EmailEventTypeCollection extends AbstractReadonlyCollection
{
    public function __construct(EmailEventType ...$types)
    {
        parent::__construct(...$types);
    }
}
