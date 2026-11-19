<?php

/*
 * Thrown when a client's API key or email token isn't one: it's malformed, or it's the other kind (e.g. an API key
 * given where an email token goes). The message is safe to show them, and never says whether a key or token exists.
 */

declare(strict_types = 1);


class InvalidTokenException extends Exception
{
}
