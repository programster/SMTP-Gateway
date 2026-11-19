<?php

/*
 * Thrown when Cloudflare's address ranges can't be fetched, or what was fetched doesn't look like them, so the ones
 * fetched before are kept.
 */

declare(strict_types = 1);


class CloudflareFetchException extends Exception
{
}
