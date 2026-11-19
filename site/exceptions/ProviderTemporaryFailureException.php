<?php

/*
 * Thrown by a provider when sending failed in a way that may succeed if retried later (e.g. a timeout, or a 4xx SMTP
 * reply).
 */

declare(strict_types = 1);


class ProviderTemporaryFailureException extends Exception
{
}
