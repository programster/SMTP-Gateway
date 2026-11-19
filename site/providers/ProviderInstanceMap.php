<?php

/*
 * The providers that have been created, by their names in settings.yaml. It's mutable, as ProviderRegistry adds
 * each provider the first time it's asked for.
 */

declare(strict_types = 1);

use Programster\Collections\AbstractMap;


/**
 * @extends AbstractMap<SmtpProviderInterface, ProviderInstanceEntry>
 */
final class ProviderInstanceMap extends AbstractMap
{
    public function __construct(ProviderInstanceEntry ...$entries)
    {
        parent::__construct(...$entries);
    }
}
