<?php

declare(strict_types = 1);

use Programster\Collections\AbstractReadonlyCollection;


/**
 * @extends AbstractReadonlyCollection<AdminUserSummary>
 */
final readonly class AdminUserSummaryCollection extends AbstractReadonlyCollection
{
    public function __construct(AdminUserSummary ...$adminUserSummaries)
    {
        parent::__construct(...$adminUserSummaries);
    }
}
