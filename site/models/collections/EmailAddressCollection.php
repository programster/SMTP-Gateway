<?php

declare(strict_types = 1);

use Programster\Collections\AbstractReadonlyCollection;


/**
 * @extends AbstractReadonlyCollection<EmailAddress>
 */
final readonly class EmailAddressCollection extends AbstractReadonlyCollection
{
    public function __construct(EmailAddress ...$emailAddresses)
    {
        parent::__construct(...$emailAddresses);
    }
}
