<?php

declare(strict_types = 1);

use Programster\Collections\AbstractReadonlyCollection;


/**
 * @extends AbstractReadonlyCollection<NavLink>
 */
final readonly class NavLinkCollection extends AbstractReadonlyCollection
{
    public function __construct(NavLink ...$links)
    {
        parent::__construct(...$links);
    }
}
