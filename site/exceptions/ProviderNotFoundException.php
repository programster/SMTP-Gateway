<?php

/*
 * Thrown when a provider's API says what was asked for doesn't exist (an HTTP 404), e.g. a domain that isn't in the
 * account.
 */

declare(strict_types = 1);


class ProviderNotFoundException extends ProviderLookupException
{
}
