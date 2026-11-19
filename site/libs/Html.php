<?php

declare(strict_types = 1);


final class Html
{
    /**
     * Escape text for output in HTML (as content, or in a quoted attribute).
     *
     * @param string|int $text - the text.
     *
     * @return string
     */
    public static function e(string|int $text) : string
    {
        return htmlspecialchars((string)$text, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }
}
