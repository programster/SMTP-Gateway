<?php

declare(strict_types = 1);


class ViewLoginPage extends Programster\AbstractView\AbstractView
{
    private readonly string $m_error;
    private readonly bool $m_canResetPassword;
    private readonly bool $m_canUseGoogle;


    /**
     * @param string $error - an error to show, or an empty string.
     *
     * @param bool $canResetPassword - whether to offer a link for resetting a forgotten password by email.
     *
     * @param bool $canUseGoogle - whether to offer logging in with Google.
     */
    public function __construct(string $error, bool $canResetPassword, bool $canUseGoogle)
    {
        $this->m_error = $error;
        $this->m_canResetPassword = $canResetPassword;
        $this->m_canUseGoogle = $canUseGoogle;
    }


    protected function renderContent() : void
    {
?>
<div class="card narrow">
    <h1>Log in</h1>
    <?= new ViewFormError($this->m_error) ?>
    <form method="post" action="/login">
        <?= new ViewCsrfField() ?>
        <label for="email">Email address</label>
        <input type="email" id="email" name="email" autocomplete="username" required autofocus>
        <label for="password">Password</label>
        <input type="password" id="password" name="password" autocomplete="current-password" required>
        <div class="form-actions"><button type="submit">Log in</button></div>
    </form>
    <?php if ($this->m_canResetPassword): ?>
        <p><a href="/forgot-password">Forgot your password?</a></p>
    <?php endif; ?>
    <?php if ($this->m_canUseGoogle): ?>
        <p class="hint">Or:</p>
        <p><a class="button" href="/login/google">Log in with Google</a></p>
    <?php endif; ?>
</div>
<?php
    }
}
