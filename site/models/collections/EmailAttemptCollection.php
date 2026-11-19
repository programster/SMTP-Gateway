<?php

declare(strict_types = 1);

use Programster\Collections\AbstractReadonlyCollection;


/**
 * @extends AbstractReadonlyCollection<EmailAttempt>
 */
final readonly class EmailAttemptCollection extends AbstractReadonlyCollection
{
    public function __construct(EmailAttempt ...$emailAttempts)
    {
        parent::__construct(...$emailAttempts);
    }
}
