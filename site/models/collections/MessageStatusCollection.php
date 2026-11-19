<?php

declare(strict_types = 1);

use Programster\Collections\AbstractReadonlyCollection;


/**
 * @extends AbstractReadonlyCollection<MessageStatus>
 */
final readonly class MessageStatusCollection extends AbstractReadonlyCollection
{
    public function __construct(MessageStatus ...$statuses)
    {
        parent::__construct(...$statuses);
    }
}
