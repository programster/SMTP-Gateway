<?php

/*
 * Thrown by a provider when it couldn't be connected to, or logged in to, so nothing was even attempted. A provider
 * that throws this is skipped for a while (see ProviderStatus), and in "fallback" mode, the next provider is tried.
 */

declare(strict_types = 1);


class ProviderUnavailableException extends ProviderTemporaryFailureException
{
}
