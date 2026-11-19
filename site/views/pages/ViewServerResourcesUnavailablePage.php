<?php

/*
 * The Server resources page, when the server's memory and vCPUs, or the database's settings,
 * couldn't be read. It says why, rather than guessing at the suggestions.
 */

declare(strict_types = 1);


class ViewServerResourcesUnavailablePage extends Programster\AbstractView\AbstractView
{
    private readonly string $m_reason;


    /**
     * @param string $reason - why they couldn't be read.
     */
    public function __construct(string $reason)
    {
        $this->m_reason = $reason;
    }


    protected function renderContent() : void
    {
?>
<h1>Server resources</h1>
<div class="card">
    <p class="flash flash-error" role="alert">The server's resources couldn't be detected, so no limits can be
        suggested: <?= Html::e($this->m_reason) ?></p>
</div>
<?php
    }
}
