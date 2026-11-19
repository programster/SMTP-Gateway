<?php

/*
 * Restoring an archived account: its email address, which archiving removed, and a password for it (emailed as a link
 * for its owner to choose, or set now), as archiving removed that too. It keeps its ID, so the logs about it are still
 * its own.
 */

declare(strict_types = 1);


class ViewRestoreUserPage extends Programster\AbstractView\AbstractView
{
    private readonly AdminUser $m_user;
    private readonly NewAccountForm $m_form;
    private readonly string $m_error;


    /**
     * @param AdminUser $user - the archived account.
     *
     * @param NewAccountForm $form - what to fill the form with.
     *
     * @param string $error - why restoring it failed, or an empty string.
     */
    public function __construct(AdminUser $user, NewAccountForm $form, string $error)
    {
        $this->m_user = $user;
        $this->m_form = $form;
        $this->m_error = $error;
    }


    protected function renderContent() : void
    {
        $path = '/admin/users/' . rawurlencode($this->m_user->getId());
?>
<p><a href="<?= Html::e($path) ?>">Back to the account</a></p>
<h1>Restore an archived account</h1>
<p>Account <code><?= Html::e($this->m_user->getId()) ?></code> keeps its ID, so the logs about it are still its own.
    Archiving removed its email address and password, so give it them again.</p>
<?= new ViewFormError($this->m_error) ?>
<form method="post" action="<?= Html::e($path) ?>/restore" class="card" id="restore-account-form">
    <?= new ViewCsrfField() ?>
    <?= new ViewAccountFields($this->m_form) ?>
    <div class="form-actions"><button type="submit">Restore the account</button></div>
</form>
<script type="module" src="/js/RestoreUserPage.js"></script>
<?php
    }
}
