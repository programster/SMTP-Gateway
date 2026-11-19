<?php

/*
 * Thrown when a provider's API couldn't be asked about how the provider is set up (e.g. it couldn't be reached, or
 * it rejected the API key), so its sender checks can't be made.
 */

declare(strict_types = 1);


class ProviderLookupException extends Exception
{
}
