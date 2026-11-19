<?php

/*
 * Whether a time is shown before its date (e.g. "21:41:12 18-11-2026"), or after it (e.g. "18-11-2026 21:41:12").
 */

declare(strict_types = 1);


enum TimePosition : string
{
    case BEFORE_DATE = 'before';
    case AFTER_DATE = 'after';


    public function getLabel() : string
    {
        return match ($this) {
            TimePosition::BEFORE_DATE => 'Before the date',
            TimePosition::AFTER_DATE => 'After the date',
        };
    }
}
