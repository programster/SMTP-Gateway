<?php

/*
 * The dialog that asks before a provider is removed, opened by each of a page's remove buttons (see ConfirmDialog.ts),
 * which say which provider it is (data-name), where to post (data-action), and what removing it would do
 * (data-warning, and data-refused when it can't be removed, from ProviderRemovalNotice).
 */

declare(strict_types = 1);


class ViewRemoveProviderDialog extends Programster\AbstractView\AbstractView
{
    private readonly string $m_version;


    /**
     * @param string $version - the version of the providers the page is showing.
     */
    public function __construct(string $version)
    {
        $this->m_version = $version;
    }


    protected function renderContent() : void
    {
?>
<dialog id="remove-provider-dialog" aria-labelledby="remove-provider-title">
    <form method="post" data-role="form">
        <?= new ViewCsrfField() ?>
        <input type="hidden" name="version" value="<?= Html::e($this->m_version) ?>">
        <h3 id="remove-provider-title">Remove <span data-role="name"></span>?</h3>
        <p>No more emails will be sent through it. Its settings are removed from
            <code><?= Html::e(SettingsFile::getPath()) ?></code>, so it would have to be added again to use it.</p>
        <p class="flash flash-warning" data-role="warning" hidden></p>
        <div class="form-actions">
            <button type="submit" class="danger" data-role="confirm">Remove</button>
            <button type="button" class="secondary" data-role="cancel">Cancel</button>
        </div>
    </form>
</dialog>
<?php
    }
}
