<?php

declare(strict_types = 1);

use Programster\Collections\AbstractReadonlyCollection;


/**
 * @extends AbstractReadonlyCollection<AuthenticatedDomain>
 */
final readonly class AuthenticatedDomainCollection extends AbstractReadonlyCollection
{
    public function __construct(AuthenticatedDomain ...$authenticatedDomains)
    {
        parent::__construct(...$authenticatedDomains);
    }
}
