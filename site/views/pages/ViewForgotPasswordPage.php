<?php

declare(strict_types = 1);


class ViewForgotPasswordPage extends Programster\AbstractView\AbstractView
{
    private readonly bool $m_isEnabled;
    private readonly bool $m_hasBeenSent;


    /**
     * @param bool $isEnabled - whether passwords can be reset by email (PUBLIC_URL, and the address the service's own
     * emails are sent from, are set).
     *
     * @param bool $hasBeenSent - whether a reset has just been asked for, to say a link is on its way.
     */
    public function __construct(bool $isEnabled, bool $hasBeenSent)
    {
        $this->m_isEnabled = $isEnabled;
        $this->m_hasBeenSent = $hasBeenSent;
    }


    protected function renderContent() : void
    {
        $minutes = PasswordResetService::TOKEN_LIFETIME_MINUTES;
?>
<div class="card narrow">
    <h1>Reset your password</h1>
    <?php if ($this->m_isEnabled === false): ?>
        <p>Resetting a password by email isn't set up on this server. An administrator can set it up on the Settings
            page (with the address the service's own emails are sent from), and PUBLIC_URL in .env.</p>
    <?php elseif ($this->m_hasBeenSent): ?>
        <p>If there's an account for that email address, a link to reset its password is on its way. It works once,
        for <?= $minutes ?> minutes. Check your spam folder if it doesn't arrive.</p>
    <?php else: ?>
        <p>Enter your account's email address, and we'll email you a link to choose a new password. You'll still need
        your second factor to log in afterwards.</p>
        <form method="post" action="/forgot-password">
            <?= new ViewCsrfField() ?>
            <label for="email">Email address</label>
            <input type="email" id="email" name="email" autocomplete="username" required autofocus>
            <div class="form-actions"><button type="submit">Email me a link</button></div>
        </form>
    <?php endif; ?>
    <p class="hint">If email isn't working, an administrator can reset it on the server:
    <code>php /var/www/site/scripts/reset-password.php &lt;email&gt;</code></p>
    <p><a href="/login">Back to log in</a></p>
</div>
<?php
    }
}
