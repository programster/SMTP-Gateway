<?php

/*
 * Thrown when a change is based on an out-of-date version of something (e.g. settings.yaml was saved by someone
 * else in the meantime), so making it would silently undo the other change.
 */

declare(strict_types = 1);


class ConflictException extends Exception
{
}
