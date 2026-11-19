<?php

/*
 * What one resource variable is set to, and the two values suggested for it: one for if every variable is changed to
 * its suggestion, and one for if only this one is changed, and every other is kept as it is.
 */

declare(strict_types = 1);


final readonly class ResourceSuggestion
{
    private ResourceVariable $m_variable;
    private int $m_current;
    private int $m_withEverythingChanged;
    private int $m_withOnlyThisChanged;


    /**
     * @param ResourceVariable $variable - the variable.
     *
     * @param int $current - what it's set to now.
     *
     * @param int $withEverythingChanged - what it should be if every variable is changed to its suggestion.
     *
     * @param int $withOnlyThisChanged - what it should be if every other variable is kept as it is.
     */
    public function __construct(
        ResourceVariable $variable,
        int $current,
        int $withEverythingChanged,
        int $withOnlyThisChanged
    )
    {
        $this->m_variable = $variable;
        $this->m_current = $current;
        $this->m_withEverythingChanged = $withEverythingChanged;
        $this->m_withOnlyThisChanged = $withOnlyThisChanged;
    }


    # Accessors
    public function getVariable() : ResourceVariable { return $this->m_variable; }
    public function getCurrent() : int { return $this->m_current; }
    public function getWithEverythingChanged() : int { return $this->m_withEverythingChanged; }
    public function getWithOnlyThisChanged() : int { return $this->m_withOnlyThisChanged; }
}
