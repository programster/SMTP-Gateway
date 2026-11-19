<?php

/*
 * A page of the documentation, already turned into HTML by DocumentationRenderer (which escapes any raw HTML in the
 * Markdown, so it's safe to output as it is), with a list of its sections beside it ("On this page"), down to the
 * level of each provider's sections on the Providers page. A section with sections within it has a caret to show or
 * hide them. DocumentationContents.ts marks the sections in view, and shows a section's subsections while it's in
 * view, or once its caret has opened them. Without it, every section is listed.
 */

declare(strict_types = 1);


class ViewDocumentationPage extends Programster\AbstractView\AbstractView
{
    // The deepest headings listed beside the page: h4, e.g. "Checking the webhook works", under "SendGrid's event
    // webhook".
    private const int DEEPEST_LISTED_LEVEL = 4;

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
        $sections = DocumentationRenderer::findSections($this->m_html, self::DEEPEST_LISTED_LEVEL);
        $hasContents = (count(DocumentationRenderer::findHeadings($this->m_html, self::DEEPEST_LISTED_LEVEL)) >= 2);
?>
<div class="documentation-layout">
<article class="documentation" id="documentation">
<?= $this->m_html ?>
</article>
<?php if ($hasContents): ?>
    <nav class="documentation-contents" id="documentation-contents" aria-labelledby="documentation-contents-title">
        <p class="documentation-contents-title" id="documentation-contents-title">On this page</p>
        <?php $this->renderSections($sections, ''); ?>
    </nav>
<?php endif; ?>
</div>
<?php if ($hasContents): ?>
    <script type="module" src="/js/DocumentationPage.js"></script>
<?php endif; ?>
<?php
    }


    /**
     * Render a list of sections, each with its subsections in a list of their own, after a caret that shows or hides
     * them.
     *
     * @param DocumentationSectionCollection $sections - the sections.
     *
     * @param string $listId - the list's id, for the caret that controls it, or an empty string for the top level.
     *
     * @return void
     */
    private function renderSections(DocumentationSectionCollection $sections, string $listId) : void
    {
        $idAttribute = ($listId === '') ? '' : ' id="' . Html::e($listId) . '" data-role="subsections"';
?>
<ul<?= $idAttribute ?>>
    <?php foreach ($sections as $section): ?>
        <?php
            /* @var $section DocumentationSection */
            $heading = $section->getHeading();
            $hasSubsections = ($section->getSubsections()->isEmpty() === false);
            $subsectionsId = "documentation-contents-{$heading->getId()}";
            $toggleLabel = "Show or hide the sections within \"{$heading->getText()}\"";
        ?>
        <li class="level-<?= $heading->getLevel() ?>">
            <div class="documentation-contents-entry">
                <?php if ($hasSubsections): ?>
                    <button type="button" class="documentation-contents-toggle" data-role="toggle"
                        aria-expanded="true" aria-controls="<?= Html::e($subsectionsId) ?>"
                        aria-label="<?= Html::e($toggleLabel) ?>"><?= new ViewIcon(Icon::CARET_RIGHT) ?></button>
                <?php endif; ?>
                <a href="#<?= Html::e(rawurlencode($heading->getId())) ?>"
                    data-heading-id="<?= Html::e($heading->getId()) ?>"><?= Html::e($heading->getText()) ?></a>
            </div>
            <?php if ($hasSubsections): ?>
                <?php $this->renderSections($section->getSubsections(), $subsectionsId); ?>
            <?php endif; ?>
        </li>
    <?php endforeach; ?>
</ul>
<?php
    }
}
