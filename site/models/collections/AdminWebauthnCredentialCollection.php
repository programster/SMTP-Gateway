<?php

declare(strict_types = 1);

use Programster\Collections\AbstractReadonlyCollection;


/**
 * @extends AbstractReadonlyCollection<AdminWebauthnCredential>
 */
final readonly class AdminWebauthnCredentialCollection extends AbstractReadonlyCollection
{
    public function __construct(AdminWebauthnCredential ...$adminWebauthnCredentials)
    {
        parent::__construct(...$adminWebauthnCredentials);
    }
}
