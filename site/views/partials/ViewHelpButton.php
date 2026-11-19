<?php

/*
 * A question mark button that opens a help dialog (see ViewHelpDialog, and HelpDialogs.ts).
 */

declare(strict_types = 1);


class ViewHelpButton extends Programster\AbstractView\AbstractView
{
    private readonly string $m_dialogId;
    private readonly string $m_label;


    /**
     * @param string $dialogId - the ID of the dialog it opens.
     *
     * @param string $label - what the dialog explains, for screen readers, e.g. "Skipping the second factor".
     */
    public function __construct(string $dialogId, string $label)
    {
        $this->m_dialogId = $dialogId;
        $this->m_label = $label;
    }


    protected function renderContent() : void
    {
?>
<button type="button" class="icon-button neutral help-button" data-role="help-button"
    data-dialog="<?= Html::e($this->m_dialogId) ?>" aria-label="Help: <?= Html::e($this->m_label) ?>"
    title="What do these mean?"><?= new ViewIcon(Icon::HELP) ?></button>
<?php
    }
}
