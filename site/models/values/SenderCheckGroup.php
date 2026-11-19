<?php

/*
 * The checks made about one subject, e.g. a sender rule ("*@example.org") or a provider's account, shown together
 * under one heading.
 */

declare(strict_types = 1);


readonly class SenderCheckGroup
{
    private string $m_heading;
    private SenderCheckCollection $m_checks;


    /**
     * @param string $heading - what was checked, e.g. "*@example.org".
     *
     * @param SenderCheck ...$checks - what was found.
     */
    public function __construct(string $heading, SenderCheck ...$checks)
    {
        $this->m_heading = $heading;
        $this->m_checks = new SenderCheckCollection(...$checks);
    }


    /**
     * Get it in the form the web interface's JSON endpoints return it in.
     *
     * @return array{heading: string, level: string, checks: list<array>}
     */
    public function toArray() : array
    {
        $checks = [];

        foreach ($this->m_checks as $check)
        {
            /* @var $check SenderCheck */
            $checks[] = $check->toArray();
        }

        return ['heading' => $this->m_heading, 'level' => $this->m_checks->getWorstLevel()->value, 'checks' => $checks];
    }


    # Accessors
    public function getHeading() : string { return $this->m_heading; }
    public function getChecks() : SenderCheckCollection { return clone $this->m_checks; }
}
