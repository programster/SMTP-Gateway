<?php

declare(strict_types = 1);

use Programster\Collections\AbstractReadonlyCollection;


/**
 * @extends AbstractReadonlyCollection<EmailMessage>
 */
final readonly class EmailMessageCollection extends AbstractReadonlyCollection
{
    public function __construct(EmailMessage ...$emailMessages)
    {
        parent::__construct(...$emailMessages);
    }
}
