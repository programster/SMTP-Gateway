<?php

/*
 * A provider that has been created, under its name in settings.yaml, for ProviderInstanceMap.
 */

declare(strict_types = 1);

use Programster\Collections\AbstractEntry;


/**
 * @extends AbstractEntry<SmtpProviderInterface>
 */
final readonly class ProviderInstanceEntry extends AbstractEntry
{
    public function __construct(int|string $key, SmtpProviderInterface $provider)
    {
        parent::__construct($key, $provider);
    }
}
