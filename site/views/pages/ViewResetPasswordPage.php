<?php

declare(strict_types = 1);


class ViewResetPasswordPage extends Programster\AbstractView\AbstractView
{
    private readonly string $m_token;
    private readonly string $m_email;
    private readonly string $m_error;


    /**
     * @param string $token - the token from the link, which the form sends back.
     *
     * @param string $email - the email address of the account it's for.
     *
     * @param string $error - an error to show, or an empty string.
     */
    public function __construct(string $token, string $email, string $error)
    {
        $this->m_token = $token;
        $this->m_email = $email;
        $this->m_error = $error;
    }


    protected function renderContent() : void
    {
?>
<div class="card narrow">
    <h1>Choose a new password</h1>
    <p>For <?= Html::e($this->m_email) ?>.</p>
    <?= new ViewFormError($this->m_error) ?>
    <form method="post" action="/reset-password/<?= Html::e($this->m_token) ?>">
        <?= new ViewCsrfField() ?>
        <input type="text" name="email" value="<?= Html::e($this->m_email) ?>" autocomplete="username" hidden>
        <?php $policy = AdminUserRepository::getPasswordPolicy(); ?>
        <label for="new_password">New password</label>
        <input type="password" id="new_password" name="new_password" autocomplete="new-password"
            minlength="<?= $policy->getMinLength() ?>" required autofocus>
        <p class="hint"><?= Html::e($policy->describe()) ?></p>
        <label for="confirm_password">New password again</label>
        <input type="password" id="confirm_password" name="confirm_password" autocomplete="new-password" required>
        <div class="form-actions"><button type="submit">Set password</button></div>
    </form>
</div>
<?php
    }
}
