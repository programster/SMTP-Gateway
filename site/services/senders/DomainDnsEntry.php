<?php

/*
 * A domain's DNS records, under the domain, for DomainDnsMap.
 */

declare(strict_types = 1);

use Programster\Collections\AbstractEntry;


/**
 * @extends AbstractEntry<DomainDns>
 */
final readonly class DomainDnsEntry extends AbstractEntry
{
    public function __construct(int|string $key, DomainDns $dns)
    {
        parent::__construct($key, $dns);
    }
}
