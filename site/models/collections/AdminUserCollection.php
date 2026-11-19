<?php

declare(strict_types = 1);

use Programster\Collections\AbstractReadonlyCollection;


/**
 * @extends AbstractReadonlyCollection<AdminUser>
 */
final readonly class AdminUserCollection extends AbstractReadonlyCollection
{
    public function __construct(AdminUser ...$adminUsers)
    {
        parent::__construct(...$adminUsers);
    }
}
