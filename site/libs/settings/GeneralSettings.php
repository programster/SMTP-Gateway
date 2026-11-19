<?php

/*
 * The general section of settings.yaml: the time zone that times are shown in, in the web interface (e.g. the logs),
 * and how dates and times are written there (see DateTimeFormat). Times are always stored in UTC, whatever this is.
 */

declare(strict_types = 1);


readonly class GeneralSettings
{
    private DateTimeZone $m_timezone;
    private DateTimeFormat $m_dateTimeFormat;


    public function __construct(DateTimeZone $timezone, DateTimeFormat $dateTimeFormat)
    {
        $this->m_timezone = $timezone;
        $this->m_dateTimeFormat = $dateTimeFormat;
    }


    /**
     * Create the settings for when there's no general section, or it's invalid: times in UTC, in the default format.
     * @return GeneralSettings
     */
    public static function createDefault() : GeneralSettings
    {
        return new GeneralSettings(new DateTimeZone('UTC'), DateTimeFormat::createDefault());
    }


    /**
     * Create the settings from a time zone's name, e.g. from the settings form.
     *
     * @param string $name - e.g. "Europe/London", or "UTC".
     *
     * @param DateTimeFormat $dateTimeFormat - how dates and times are written.
     *
     * @return GeneralSettings
     *
     * @throws InvalidInputException - if it isn't a time zone PHP knows.
     */
    public static function createUsingTimezoneName(string $name, DateTimeFormat $dateTimeFormat) : GeneralSettings
    {
        $name = trim($name);

        if ($name !== 'UTC' && in_array($name, DateTimeZone::listIdentifiers(), true) === false)
        {
            throw new InvalidInputException("The time zone must be one such as \"Europe/London\", not \"{$name}\".");
        }

        return new GeneralSettings(new DateTimeZone($name), $dateTimeFormat);
    }


    # Accessors
    public function getTimezone() : DateTimeZone { return $this->m_timezone; }
    public function getDateTimeFormat() : DateTimeFormat { return $this->m_dateTimeFormat; }
}
