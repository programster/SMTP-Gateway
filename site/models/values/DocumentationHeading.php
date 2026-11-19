<?php

/*
 * One heading of a page of the documentation, for the list of the page's sections beside it ("On this page").
 */

declare(strict_types = 1);


final readonly class DocumentationHeading
{
    private int $m_level;
    private string $m_id;
    private string $m_text;


    /**
     * @param int $level - its level, from 2 (a section) to 6.
     *
     * @param string $id - its id in the page, which a link to it ends with, e.g. "sendgrids-api-key".
     *
     * @param string $text - its text, as plain text (not HTML).
     *
     * @throws InvalidArgumentException - if the level isn't from 2 to 6, or the id or text is empty.
     */
    public function __construct(int $level, string $id, string $text)
    {
        if ($level < 2 || $level > 6)
        {
            throw new InvalidArgumentException("A heading's level must be from 2 to 6, not {$level}.");
        }

        if ($id === '' || trim($text) === '')
        {
            throw new InvalidArgumentException('A heading needs an id and some text.');
        }

        $this->m_level = $level;
        $this->m_id = $id;
        $this->m_text = $text;
    }


    # Accessors
    public function getLevel() : int { return $this->m_level; }
    public function getId() : string { return $this->m_id; }
    public function getText() : string { return $this->m_text; }
}
