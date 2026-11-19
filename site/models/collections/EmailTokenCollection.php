<?php

declare(strict_types = 1);

use Programster\Collections\AbstractReadonlyCollection;


/**
 * @extends AbstractReadonlyCollection<EmailToken>
 */
final readonly class EmailTokenCollection extends AbstractReadonlyCollection
{
    public function __construct(EmailToken ...$emailTokens)
    {
        parent::__construct(...$emailTokens);
    }
}
