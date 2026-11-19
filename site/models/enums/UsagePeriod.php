<?php

/*
 * How far back the dashboard's "most used email tokens" counts go, chosen with its links: the last day, week, month
 * or year.
 */

declare(strict_types = 1);


enum UsagePeriod : string
{
    case DAY = 'day';
    case WEEK = 'week';
    case MONTH = 'month';
    case YEAR = 'year';


    public function getLabel() : string
    {
        return match ($this) {
            UsagePeriod::DAY => 'Day',
            UsagePeriod::WEEK => 'Week',
            UsagePeriod::MONTH => 'Month',
            UsagePeriod::YEAR => 'Year',
        };
    }


    /**
     * Get how many days it is, to compare with how long email records are kept.
     * @return int
     */
    public function getDays() : int
    {
        return match ($this) {
            UsagePeriod::DAY => 1,
            UsagePeriod::WEEK => 7,
            UsagePeriod::MONTH => 30,
            UsagePeriod::YEAR => 365,
        };
    }


    /**
     * Get the time window it is, for counting emails, and for the link to list them.
     * @return TimeWindow
     */
    public function getWindow() : TimeWindow
    {
        return match ($this) {
            UsagePeriod::DAY => TimeWindow::LAST_24_HOURS,
            UsagePeriod::WEEK => TimeWindow::LAST_7_DAYS,
            UsagePeriod::MONTH => TimeWindow::LAST_30_DAYS,
            UsagePeriod::YEAR => TimeWindow::LAST_365_DAYS,
        };
    }
}
