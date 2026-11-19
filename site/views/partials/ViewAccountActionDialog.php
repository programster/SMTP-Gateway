<?php

/*
 * The dialog for archiving or deleting an account: what will happen, and a field for the password of whoever is doing
 * it. Each button that opens it (see ConfirmDialog.ts) says which account, with its data-action (where the form
 * posts) and data-name (the account, as shown in the dialog). Submitting it does the action, once the server has
 * checked the password.
 */

declare(strict_types = 1);


class ViewAccountActionDialog extends Programster\AbstractView\AbstractView
{
    private readonly AccountAction $m_action;


    public function __construct(AccountAction $action)
    {
        $this->m_action = $action;
    }


    protected function renderContent() : void
    {
        $action = $this->m_action;
        $id = $action->getDialogId();
?>
<dialog id="<?= Html::e($id) ?>" aria-labelledby="<?= Html::e($id) ?>-title">
    <form method="post" data-role="form">
        <?= new ViewCsrfField() ?>
        <?php if ($action === AccountAction::ARCHIVE): ?>
            <h3 id="<?= Html::e($id) ?>-title">Archive <span data-role="name"></span>?</h3>
            <p>Archiving is a delete that's good enough for a GDPR erasure request. The account is blocked, and its
                email address, password, authenticator app, security keys and backup codes are removed, and can't be
                recovered. Only its ID is kept, which is all the logs name it by, so they still make sense.</p>
            <p>It can be restored later, but its email address will have to be entered again, and it will need a new
                password. To remove it completely, delete it from the archived accounts afterwards.</p>
        <?php else: ?>
            <h3 id="<?= Html::e($id) ?>-title">Delete <span data-role="name"></span> for good?</h3>
            <p>The account, and everything linked to it, is removed from the database, and this can't be undone. Log
                entries that name it keep its ID, which will then show as a deleted account.</p>
        <?php endif; ?>
        <label for="<?= Html::e($id) ?>-password">Your password</label>
        <input type="password" id="<?= Html::e($id) ?>-password" name="current_password"
            autocomplete="current-password" required>
        <div class="form-actions">
            <button type="submit" class="danger"><?= Html::e($action->getButtonLabel()) ?></button>
            <button type="button" class="secondary" data-role="cancel">Cancel</button>
        </div>
    </form>
</dialog>
<?php
    }
}
