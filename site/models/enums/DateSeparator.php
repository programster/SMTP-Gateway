<?php

/*
 * What goes between a date's day, month and year, e.g. 18-11-2026, 18/11/2026 or 18.11.2026.
 */

declare(strict_types = 1);


enum DateSeparator : string
{
    case DASH = '-';
    case SLASH = '/';
    case DOT = '.';


    public function getLabel() : string
    {
        return match ($this) {
            DateSeparator::DASH => 'Dashes',
            DateSeparator::SLASH => 'Slashes',
            DateSeparator::DOT => 'Dots',
        };
    }
}
