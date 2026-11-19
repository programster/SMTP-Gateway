<?php

/*
 * A section of the side panel's menu, e.g. "Admin", whose links (its secondary menu) show when it's clicked. It's
 * open to begin with when the page being shown is one of its links.
 */

declare(strict_types = 1);


final readonly class NavSection implements NavEntry
{
    private string $m_label;
    private Icon $m_icon;
    private NavLinkCollection $m_links;


    /**
     * @param string $label - what the section is called, e.g. "Admin".
     *
     * @param Icon $icon - the icon beside it, or Icon::NONE.
     *
     * @param NavLink ...$links - its links, in order. A section needs at least two, as one link would just be a link.
     *
     * @throws LogicException - if it's given fewer than two links.
     */
    public function __construct(string $label, Icon $icon, NavLink ...$links)
    {
        if (count($links) < 2)
        {
            throw new LogicException("The \"{$label}\" section needs at least two links. Use a NavLink for one.");
        }

        $this->m_label = $label;
        $this->m_icon = $icon;
        $this->m_links = new NavLinkCollection(...$links);
    }


    public function isCurrentFor(string $requestPath) : bool
    {
        $isCurrent = false;

        foreach ($this->m_links as $link)
        {
            $isCurrent = ($isCurrent || $link->isCurrentFor($requestPath));
        }

        return $isCurrent;
    }


    # Accessors
    public function getLabel() : string { return $this->m_label; }
    public function getIcon() : Icon { return $this->m_icon; }
    public function getLinks() : NavLinkCollection { return $this->m_links; }
}
