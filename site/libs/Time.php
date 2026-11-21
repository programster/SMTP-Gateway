<?php

/*
 * Helpers for times, as stored in (and read from) the database.
 */

declare(strict_types = 1);


final class Time
{
    /**
     * Get the current time, in the form the database stores it (with microseconds and the time zone).
     *
     * @return string - e.g. "2026-11-12 19:33:01.123456+00:00".
     */
    public static function now() : string
    {
        return (new DateTimeImmutable())->format('Y-m-d H:i:s.uP');
    }


    /**
     * Format a time read from the database for showing to a person, in the time zone and format set on the Settings
     * page, e.g. "19:33:01 12-11-2026".
     *
     * @param string $databaseTime - the time, as read from the database.
     *
     * @return string
     */
    public static function format(string $databaseTime) : string
    {
        return self::formatDateTime(new DateTimeImmutable($databaseTime));
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
     * @param string $databaseTime - the time, as read from the database.
     * @return int - 0 for less than a day ago.
     */
    public static function getDaysSince(string $databaseTime) : int
    {
        $seconds = time() - (new DateTimeImmutable($databaseTime))->getTimestamp();
        return max(0, intdiv($seconds, 86400));
    }


    /**
     * Get how many whole days passed between two times.
     *
     * @param string $from - the earlier time, as read from the database.
     *
     * @param string $to - the later time, as read from the database.
     *
     * @return int - 0 for less than a day.
     */
    public static function getDaysBetween(string $from, string $to) : int
    {
        $seconds = (new DateTimeImmutable($to))->getTimestamp() - (new DateTimeImmutable($from))->getTimestamp();
        return max(0, intdiv($seconds, 86400));
    }


    /**
     * Describe how long ago something was last used: in whole days, e.g. "12 days ago", or, if it was less than a day
     * ago, as describeAgo() does, e.g. "3 hours ago" or "10 seconds ago".
     *
     * @param string $databaseTime - the time, as read from the database.
     *
     * @return string
     */
    public static function describeDaysAgo(string $databaseTime) : string
    {
        $time = new DateTimeImmutable($databaseTime);
        $days = self::getDaysSince($databaseTime);

        return match (true) {
            $days === 0 => self::describeAgo($time->format('U.u')),
            $days === 1 => '1 day ago',
            default => "{$days} days ago",
        };
    }


    /**
     * Describe how long ago a unix timestamp was, e.g. "5 seconds ago", "2.5 hours ago" or "3.5 days ago": whole
     * seconds, then minutes, hours, days and years, to one decimal place below 10, and whole ones (rounded down) from
     * there.
     *
     * @param string $timestamp - e.g. "1763497200.1234".
     *
     * @return string
     */
    public static function describeAgo(string $timestamp) : string
    {
        $seconds = microtime(true) - (float)$timestamp;

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
     * @param string $databaseTime - the time, as read from the database.
     *
     * @return string
     */
    public static function toIso(string $databaseTime) : string
    {
        return (new DateTimeImmutable($databaseTime))->format(DATE_ATOM);
    }
}
