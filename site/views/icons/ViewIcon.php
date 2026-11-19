<?php

/*
 * An icon (see Icon), or nothing, for Icon::NONE. The icon's SVG is put into the page as it is, so it's drawn in the
 * text's colour (Phosphor's icons fill with "currentColor"). It's hidden from screen readers, so what it's on needs a
 * label of its own: text beside it, or, for a button that's only an icon, an aria-label.
 */

declare(strict_types = 1);


class ViewIcon extends Programster\AbstractView\AbstractView
{
    private readonly Icon $m_icon;


    public function __construct(Icon $icon)
    {
        $this->m_icon = $icon;
    }


    protected function renderContent() : void
    {
        if ($this->m_icon !== Icon::NONE)
        {
            echo self::fetchSvg($this->m_icon);
        }
    }


    /**
     * Read an icon's SVG, with the attributes it needs here added, once per request for each icon.
     * @param Icon $icon - the icon, which mustn't be NONE.
     * @return string
     * @throws LogicException - if its file can't be read, or isn't an SVG.
     */
    private static function fetchSvg(Icon $icon) : string
    {
        static $svgs = [];

        if (!isset($svgs[$icon->value]))
        {
            $svg = @file_get_contents($icon->getFilePath());

            if ($svg === false || !str_starts_with($svg, '<svg '))
            {
                throw new LogicException("The icon \"{$icon->value}\" can't be read from {$icon->getFilePath()}.");
            }

            $attributes = '<svg class="icon" width="18" height="18" aria-hidden="true" focusable="false" ';
            $svgs[$icon->value] = $attributes . substr($svg, strlen('<svg '));
        }

        return $svgs[$icon->value];
    }
}
