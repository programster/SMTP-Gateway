<?php

/*
 * Which pages a link in the side panel's menu counts as current on (see NavLink).
 */

declare(strict_types = 1);


enum NavMatch
{
    // Its own page, and every page below it, e.g. /messages and /messages/123.
    case PAGE_AND_BELOW;

    // Only its own page, for a link whose path starts the paths of other links, e.g. /documentation, whose pages are
    // links of their own.
    case PAGE_ONLY;
}
