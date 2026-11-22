<?php

/*
 * Helpers for times, which the database stores as UTC Unix timestamps, in seconds: an int for most, and a float for
 * those that need fractions of a second (when emails and attempts were dispatched and finished).
 */

declare(strict_types = 1);


final class Time
{
    /**
     * Get the current time, in the form the database stores most times.
     * @return int - a Unix timestamp, in whole seconds, e.g. 1794511981.
     */
    public static function now() : int
    {
        return time();
    }


    /**
     * Get the current time, in the form the database stores the times that need fractions of a second.
     * @return float - a Unix timestamp, e.g. 1794511981.123456.
     */
    public static function nowPrecise() : float
    {
        return microtime(true);
    }


    /**
     * Get a time read from the database as a date and time in UTC, in whole seconds.
     * @param int|float $databaseTime - the time, as a Unix timestamp.
     * @return DateTimeImmutable
     */
    public static function toDateTime(int|float $databaseTime) : DateTimeImmutable
    {
        return new DateTimeImmutable('@' . (int)floor($databaseTime));
    }


    /**
     * Format a time read from the database for showing to a person, in the time zone and format set on the Settings
     * page, e.g. "19:33:01 12-11-2026".
     *
     * @param int|float $databaseTime - the time, as a Unix timestamp.
     *
     * @return string
     */
    public static function format(int|float $databaseTime) : string
    {
        return self::formatDateTime(self::toDateTime($databaseTime));
    }


    /**
     * Format a time for showing to a person, in the time zone and format set on the Settings page.
     *
     * @param DateTimeImmutable $time - the time, in any time zone.
     *
     * @return string - e.g. "19:33:01 12-11-2026".
     */
    public static function formatDateTime(DateTimeImmutable $time) : string
    {
        $settings = Settings::get();
        return $settings->getDateTimeFormat()->format($time->setTimezone($settings->getTimezone()), '');
    }


    /**
     * Format a unix timestamp with a fraction of a second (as the log table stores them) for showing to a
     * person, in the configured time zone and format, e.g. "19:33:01.1234 12-11-2026".
     *
     * @param string $timestamp - e.g. "1794511981.1234".
     *
     * @return string
     */
    public static function formatUnixTimestamp(string $timestamp) : string
    {
        $parts = explode('.', $timestamp . '.0');
        $settings = Settings::get();
        $time = (new DateTimeImmutable('@' . $parts[0]))->setTimezone($settings->getTimezone());
        return $settings->getDateTimeFormat()->format($time, str_pad(substr($parts[1], 0, 4), 4, '0'));
    }


    /**
     * Get how many whole days ago a time read from the database was, e.g. for an API key's age.
     * @param int|float $databaseTime - the time, as a Unix timestamp.
     * @return int - 0 for less than a day ago.
     */
    public static function getDaysSince(int|float $databaseTime) : int
    {
        $seconds = time() - (int)floor($databaseTime);
        return max(0, intdiv($seconds, 86400));
    }


    /**
     * Get how many whole days passed between two times.
     *
     * @param int|float $from - the earlier time, as a Unix timestamp.
     *
     * @param int|float $to - the later time, as a Unix timestamp.
     *
     * @return int - 0 for less than a day.
     */
    public static function getDaysBetween(int|float $from, int|float $to) : int
    {
        $seconds = (int)floor($to) - (int)floor($from);
        return max(0, intdiv($seconds, 86400));
    }


    /**
     * Describe how long ago something was last used: in whole days, e.g. "12 days ago", or, if it was less than a day
     * ago, as describeAgo() does, e.g. "3 hours ago" or "10 seconds ago".
     *
     * @param int|float $databaseTime - the time, as a Unix timestamp.
     *
     * @return string
     */
    public static function describeDaysAgo(int|float $databaseTime) : string
    {
        $days = self::getDaysSince($databaseTime);

        return match (true) {
            $days === 0 => self::describeAgo($databaseTime),
            $days === 1 => '1 day ago',
            default => "{$days} days ago",
        };
    }


    /**
     * Describe how long ago a unix timestamp was, e.g. "5 seconds ago", "2.5 hours ago" or "3.5 days ago": whole
     * seconds, then minutes, hours, days and years, to one decimal place below 10, and whole ones (rounded down) from
     * there.
     *
     * @param int|float $timestamp - e.g. 1763497200.1234.
     *
     * @return string
     */
    public static function describeAgo(int|float $timestamp) : string
    {
        $seconds = microtime(true) - $timestamp;

        // Each unit, the most seconds it's used for, and how many seconds one of it is.
        $units = [
            ['minute', 3600, 60],
            ['hour', 86400, 3600],
            ['day', 365 * 86400, 86400],
            ['year', PHP_INT_MAX, 365 * 86400],
        ];

        if ($seconds < 1)
        {
            $description = 'just now';
        }
        elseif ($seconds < 60)
        {
            $whole = (int)floor($seconds);
            $description = $whole . (($whole === 1) ? ' second ago' : ' seconds ago');
        }
        else
        {
            $description = '';

            foreach ($units as [$unit, $below, $size])
            {
                if ($description === '' && $seconds < $below)
                {
                    $count = $seconds / $size;
                    $shown = ($count < 10) ? round($count, 1) : floor($count);
                    $text = rtrim(rtrim(number_format($shown, 1, '.', ','), '0'), '.');
                    $description = "{$text} {$unit}" . (($text === '1') ? '' : 's') . ' ago';
                }
            }
        }

        return $description;
    }


    /**
     * Turn a time a person entered (e.g. in a datetime-local input), which is in the configured time zone, into a
     * unix timestamp.
     *
     * @param string $localTime - e.g. "2026-11-12T19:33" or "2026-11-12 19:33:01".
     *
     * @return int
     *
     * @throws InvalidInputException - if it isn't a valid date and time.
     */
    public static function parseLocalTime(string $localTime) : int
    {
        $formats = ['Y-m-d\\TH:i', 'Y-m-d\\TH:i:s', 'Y-m-d H:i', 'Y-m-d H:i:s'];
        $timezone = Settings::get()->getTimezone();
        $timestamp = -1;

        foreach ($formats as $format)
        {
            $parsed = DateTimeImmutable::createFromFormat('!' . $format, trim($localTime), $timezone);

            if ($timestamp === -1 && $parsed !== false && $parsed->format($format) === trim($localTime))
            {
                $timestamp = $parsed->getTimestamp();
            }
        }

        if ($timestamp === -1)
        {
            throw new InvalidInputException("\"{$localTime}\" isn't a valid date and time.");
        }

        return $timestamp;
    }


    /**
     * Format a time read from the database as ISO 8601, for the API, e.g. "2026-11-12T19:33:01+00:00".
     *
     * @param int|float $databaseTime - the time, as a Unix timestamp.
     *
     * @return string
     */
    public static function toIso(int|float $databaseTime) : string
    {
        return self::toDateTime($databaseTime)->format(DATE_ATOM);
    }
}
