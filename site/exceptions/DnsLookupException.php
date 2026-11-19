<?php

/*
 * Thrown when a DNS lookup fails (e.g. the DNS server didn't answer), as opposed to it answering that there's no
 * such record.
 */

declare(strict_types = 1);


class DnsLookupException extends Exception
{
}
