<?php

declare(strict_types = 1);

use Programster\Collections\AbstractReadonlyCollection;


/**
 * @extends AbstractReadonlyCollection<VerifiedAddress>
 */
final readonly class VerifiedAddressCollection extends AbstractReadonlyCollection
{
    public function __construct(VerifiedAddress ...$verifiedAddresses)
    {
        parent::__construct(...$verifiedAddresses);
    }
}
