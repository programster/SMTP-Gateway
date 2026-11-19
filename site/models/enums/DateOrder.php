<?php

/*
 * The order a date's day, month and year are shown in, e.g. 18-11-2026 (as in the UK), 11-18-2026 (as in the US), or
 * 2026-11-18 (ISO 8601).
 */

declare(strict_types = 1);


enum DateOrder : string
{
    case DAY_MONTH_YEAR = 'dmy';
    case MONTH_DAY_YEAR = 'mdy';
    case YEAR_MONTH_DAY = 'ymd';


    public function getLabel() : string
    {
        return match ($this) {
            DateOrder::DAY_MONTH_YEAR => 'Day, month, year (as in the UK)',
            DateOrder::MONTH_DAY_YEAR => 'Month, day, year (as in the US)',
            DateOrder::YEAR_MONTH_DAY => 'Year, month, day (ISO 8601)',
        };
    }


    /**
     * Get the PHP date() format for a date in this order.
     * @param DateSeparator $separator - what goes between the parts.
     * @return string - e.g. "d-m-Y".
     */
    public function getPhpFormat(DateSeparator $separator) : string
    {
        $parts = match ($this) {
            DateOrder::DAY_MONTH_YEAR => ['d', 'm', 'Y'],
            DateOrder::MONTH_DAY_YEAR => ['m', 'd', 'Y'],
            DateOrder::YEAR_MONTH_DAY => ['Y', 'm', 'd'],
        };

        return implode($separator->value, $parts);
    }
}
