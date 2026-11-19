<?php

/*
 * How many log entries are shown on a page.
 */

declare(strict_types = 1);


enum LogPageSize : int
{
    case TWENTY_FIVE = 25;
    case FIFTY = 50;
    case ONE_HUNDRED = 100;
    case TWO_HUNDRED = 200;
    case FIVE_HUNDRED = 500;
}
