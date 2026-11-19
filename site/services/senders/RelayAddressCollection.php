<?php

declare(strict_types = 1);

use Programster\Collections\AbstractReadonlyCollection;


/**
 * @extends AbstractReadonlyCollection<RelayAddress>
 */
final readonly class RelayAddressCollection extends AbstractReadonlyCollection
{
    public function __construct(RelayAddress ...$relayAddresses)
    {
        parent::__construct(...$relayAddresses);
    }
}
