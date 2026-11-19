<?php

/*
 * A dialog explaining something in full (e.g. each of a setting's choices), so the form itself only needs a short line
 * for each. It's opened by a question mark button (ViewHelpButton), which can be somewhere a dialog can't, such as in a
 * <legend>. It loads the script that opens and closes it (HelpDialogsOnPage.ts), so any page can use one.
 */

declare(strict_types = 1);


class ViewHelpDialog extends Programster\AbstractView\AbstractView
{
    private readonly string $m_id;
    private readonly string $m_title;
    private readonly HelpEntryCollection $m_entries;
    private readonly string $m_footnote;


    /**
     * @param string $id - the dialog's ID, unique on the page, e.g. "google-mfa-help".
     *
     * @param string $title - what it explains, e.g. "Skipping the second factor". It's also the button's label.
     *
     * @param HelpEntryCollection $entries - each term, and what it means.
     *
     * @param string $footnote - anything more to say, after the entries, or an empty string.
     */
    public function __construct(string $id, string $title, HelpEntryCollection $entries, string $footnote)
    {
        $this->m_id = $id;
        $this->m_title = $title;
        $this->m_entries = $entries;
        $this->m_footnote = $footnote;
    }


    protected function renderContent() : void
    {
        $titleId = "{$this->m_id}-title";
?>
<dialog id="<?= Html::e($this->m_id) ?>" class="help-dialog" aria-labelledby="<?= Html::e($titleId) ?>">
    <div class="dialog-header">
        <h3 id="<?= Html::e($titleId) ?>"><?= Html::e($this->m_title) ?></h3>
        <button type="button" class="icon-button neutral" data-role="close" aria-label="Close"
            title="Close"><?= new ViewIcon(Icon::CLOSE) ?></button>
    </div>
    <dl class="help-entries">
    <?php foreach ($this->m_entries as $entry): ?>
        <?php /* @var $entry HelpEntry */ ?>
        <dt><?= Html::e($entry->getTerm()) ?></dt>
        <dd><?= Html::e($entry->getExplanation()) ?></dd>
    <?php endforeach; ?>
    </dl>
    <?php if ($this->m_footnote !== ''): ?>
        <p class="hint"><?= Html::e($this->m_footnote) ?></p>
    <?php endif; ?>
</dialog>
<script type="module" src="/js/HelpDialogsOnPage.js"></script>
<?php
    }
}
