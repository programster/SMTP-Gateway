<?php

/*
 * One entry of a help dialog (see ViewHelpDialog): a term, e.g. one of a setting's choices, and what it means.
 */

declare(strict_types = 1);


final readonly class HelpEntry
{
    private string $m_term;
    private string $m_explanation;


    /**
     * @param string $term - what's being explained, e.g. "Never".
     *
     * @param string $explanation - what it means, as plain text.
     */
    public function __construct(string $term, string $explanation)
    {
        $this->m_term = $term;
        $this->m_explanation = $explanation;
    }


    # Accessors
    public function getTerm() : string { return $this->m_term; }
    public function getExplanation() : string { return $this->m_explanation; }
}
