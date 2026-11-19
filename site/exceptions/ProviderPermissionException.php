<?php

/*
 * Thrown when a provider's API refuses a request because the API key isn't allowed to make it (an HTTP 403), so
 * that one check can be skipped, with a note saying which permission would allow it.
 */

declare(strict_types = 1);


class ProviderPermissionException extends ProviderLookupException
{
}
