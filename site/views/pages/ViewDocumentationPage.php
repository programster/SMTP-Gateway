<?php

/*
 * A page of the documentation, already turned into HTML by DocumentationRenderer (which escapes any raw HTML in the
 * Markdown, so it's safe to output as it is).
 */

declare(strict_types = 1);


class ViewDocumentationPage extends Programster\AbstractView\AbstractView
{
    private readonly string $m_html;


    /**
     * @param string $html - the page, from DocumentationRenderer.
     */
    public function __construct(string $html)
    {
        $this->m_html = $html;
    }


    protected function renderContent() : void
    {
?>
<article class="documentation">
<?= $this->m_html ?>
</article>
<?php
    }
}
