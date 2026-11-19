<?php

/*
 * Thrown inside a provider when a connection it kept open turns out to have been closed by the server, before
 * anything was accepted, so it's safe to reconnect and try again. It never leaves the provider.
 */

declare(strict_types = 1);


class StaleConnectionException extends Exception
{
}
