<?php

/*
 * Thrown by a provider when it refused the email outright (e.g. a 5xx SMTP reply), so retrying won't help.
 */

declare(strict_types = 1);


class ProviderPermanentFailureException extends Exception
{
}
