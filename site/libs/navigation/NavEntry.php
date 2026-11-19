<?php

/*
 * One entry in the side panel's menu: a link (NavLink), or a section of links that opens when it's clicked
 * (NavSection).
 */

declare(strict_types = 1);


interface NavEntry
{
    /**
     * Whether the page at this path is this entry, or is in it.
     *
     * @param string $requestPath - the path of the page being shown, e.g. "/messages/123".
     *
     * @return bool
     */
    public function isCurrentFor(string $requestPath) : bool;


    public function getLabel() : string;


    public function getIcon() : Icon;
}
