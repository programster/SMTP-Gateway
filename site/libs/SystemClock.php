<?php

/*
 * The system clock, as a PSR-20 clock (which the TOTP library needs).
 */

declare(strict_types = 1);


final class SystemClock implements \Psr\Clock\ClockInterface
{
    public function now() : DateTimeImmutable
    {
        return new DateTimeImmutable();
    }
}
