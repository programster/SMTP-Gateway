<?php

/*
 * A link in the side panel's menu, e.g. "Emails" to /messages. It's the current page for its own path, and, unless
 * it's told otherwise (see NavMatch), for any page below it (e.g. /messages/123). "/" only ever matches itself.
 */

declare(strict_types = 1);


final readonly class NavLink implements NavEntry
{
    private string $m_label;
    private string $m_path;
    private NavMatch $m_match;
    private Icon $m_icon;


    /**
     * @param string $label - what the link says, e.g. "Emails".
     *
     * @param string $path - where it goes, e.g. "/messages". It must start with "/", and not end with one (but "/").
     *
     * @param NavMatch $match - which pages it's current on: by default its own, and every page below it.
     *
     * @param Icon $icon - the icon beside it, or none (the default, as for a link in a section).
     *
     * @throws LogicException - if the path isn't one of this site's, written as above.
     */
    public function __construct(
        string $label,
        string $path,
        NavMatch $match = NavMatch::PAGE_AND_BELOW,
        Icon $icon = Icon::NONE
    )
    {
        if (preg_match('#^/([a-z0-9-]+(/[a-z0-9-]+)*)?$#', $path) !== 1)
        {
            throw new LogicException("\"{$path}\" isn't a path of this site, like \"/messages\".");
        }

        $this->m_label = $label;
        $this->m_path = $path;
        $this->m_match = $match;
        $this->m_icon = $icon;
    }


    public function isCurrentFor(string $requestPath) : bool
    {
        $canBeBelow = ($this->m_path !== '/' && $this->m_match === NavMatch::PAGE_AND_BELOW);
        $isBelow = ($canBeBelow && str_starts_with($requestPath, "{$this->m_path}/"));
        return ($requestPath === $this->m_path || $isBelow);
    }


    # Accessors
    public function getLabel() : string { return $this->m_label; }
    public function getPath() : string { return $this->m_path; }
    public function getIcon() : Icon { return $this->m_icon; }
}
