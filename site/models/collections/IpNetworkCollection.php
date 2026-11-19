<?php

declare(strict_types = 1);

use Programster\Collections\AbstractReadonlyCollection;


/**
 * @extends AbstractReadonlyCollection<IpNetwork>
 */
final readonly class IpNetworkCollection extends AbstractReadonlyCollection
{
    public function __construct(IpNetwork ...$ipNetworks)
    {
        parent::__construct(...$ipNetworks);
    }
}
