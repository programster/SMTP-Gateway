<?php

/*
 * Shown once, straight after an administrator sets a temporary password for an account: the only time it's available.
 */

declare(strict_types = 1);


class ViewTemporaryPasswordPage extends Programster\AbstractView\AbstractView
{
    private readonly AdminUser $m_user;
    private readonly string $m_password;


    /**
     * @param AdminUser $user - the account.
     *
     * @param string $password - its new temporary password, which is only shown now.
     */
    public function __construct(AdminUser $user, string $password)
    {
        $this->m_user = $user;
        $this->m_password = $password;
    }


    protected function renderContent() : void
    {
        $path = '/admin/users/' . rawurlencode($this->m_user->getId());
?>
<h1>Temporary password set</h1>
<p class="flash flash-error">Copy the password now. It can't be shown again, as only a hash of it is stored.</p>
<div class="card" id="created-secret">
    <label for="temporary-password">Temporary password for <?= Html::e($this->m_user->getEmail()) ?></label>
    <div class="secret-box"><input type="text" id="temporary-password" value="<?= Html::e($this->m_password) ?>"
        readonly autocomplete="off"><button type="button" class="secondary"
        data-copy-target="temporary-password">Copy</button></div>
    <p class="hint">Give it to them somewhere safe. They'll have to choose their own password when they next log in, and
        they'll still need their second factor, if they have one.</p>
    <p><a href="<?= Html::e($path) ?>">Done, I've copied the password</a></p>
</div>
<script type="module" src="/js/SecretCreatedPage.js"></script>
<?php
    }
}
