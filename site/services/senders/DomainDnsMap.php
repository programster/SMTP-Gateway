<?php

/*
 * Domains' DNS records, by domain, so each domain is only looked up once.
 */

declare(strict_types = 1);

use Programster\Collections\AbstractMap;


/**
 * @extends AbstractMap<DomainDns, DomainDnsEntry>
 */
final class DomainDnsMap extends AbstractMap
{
    public function __construct(DomainDnsEntry ...$entries)
    {
        parent::__construct(...$entries);
    }
}
