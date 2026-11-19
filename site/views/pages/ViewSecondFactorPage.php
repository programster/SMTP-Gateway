<?php

/*
 * The second step of logging in: a code from an authenticator app, or a security key, or (for when they've been lost)
 * one of the account's backup codes.
 */

declare(strict_types = 1);


class ViewSecondFactorPage extends Programster\AbstractView\AbstractView
{
    private readonly bool $m_hasTotp;
    private readonly bool $m_hasSecurityKeys;
    private readonly bool $m_hasBackupCodes;
    private readonly bool $m_isBackupCodeOpen;
    private readonly string $m_error;


    /**
     * @param bool $hasTotp - whether the account has an authenticator app set up.
     *
     * @param bool $hasSecurityKeys - whether it has any security keys.
     *
     * @param bool $hasBackupCodes - whether it has any unused backup codes.
     *
     * @param bool $isBackupCodeOpen - whether the backup code form starts open, as it does after a backup code was
     * refused.
     *
     * @param string $error - why the last attempt failed, or an empty string.
     */
    public function __construct(
        bool $hasTotp,
        bool $hasSecurityKeys,
        bool $hasBackupCodes,
        bool $isBackupCodeOpen,
        string $error
    )
    {
        $this->m_hasTotp = $hasTotp;
        $this->m_hasSecurityKeys = $hasSecurityKeys;
        $this->m_hasBackupCodes = $hasBackupCodes;
        $this->m_isBackupCodeOpen = $isBackupCodeOpen;
        $this->m_error = $error;
    }


    protected function renderContent() : void
    {
?>
<div class="card narrow">
    <h1>Two-factor authentication</h1>
    <?= new ViewFormError($this->m_error) ?>

    <?php if ($this->m_hasSecurityKeys): ?>
        <div id="security-key-login"
             data-options-url="/login/second-factor/security-key/options"
             data-verify-url="/login/second-factor/security-key"
             data-csrf-token="<?= Html::e(WebSession::getCsrfToken()) ?>">
            <p>Insert your security key (e.g. YubiKey), then press the button and touch the key.</p>
            <p class="flash flash-error" data-role="error" hidden></p>
            <button type="button" data-role="start">Use security key</button>
        </div>
    <?php endif; ?>

    <?php if ($this->m_hasTotp): ?>
        <?php if ($this->m_hasSecurityKeys): ?><h2>Or use your authenticator app</h2><?php endif; ?>
        <form method="post" action="/login/second-factor/totp">
            <?= new ViewCsrfField() ?>
            <label for="code">6-digit code from your authenticator app</label>
            <input type="text" id="code" name="code" inputmode="numeric" pattern="[0-9 ]{6,7}"
                autocomplete="one-time-code" required <?=
                ($this->m_hasSecurityKeys || $this->m_isBackupCodeOpen) ? '' : 'autofocus' ?>>
            <div class="form-actions"><button type="submit">Verify</button></div>
        </form>
    <?php endif; ?>

    <?php if ($this->m_hasBackupCodes): ?>
        <details class="backup-code-login"<?= $this->m_isBackupCodeOpen ? ' open' : '' ?>>
            <summary>Lost your <?= $this->describeFactors() ?>? Use a backup code</summary>
            <form method="post" action="/login/second-factor/backup-code">
                <?= new ViewCsrfField() ?>
                <label for="backup_code">Backup code</label>
                <input type="text" id="backup_code" name="backup_code" autocomplete="off" autocapitalize="none"
                    spellcheck="false" placeholder="e.g. k7m2p-x9q4r" required<?=
                    $this->m_isBackupCodeOpen ? ' autofocus' : '' ?>>
                <p class="hint">One of the codes you saved when you set up two-factor authentication. Each one only
                    works once.</p>
                <div class="form-actions"><button type="submit">Log in with a backup code</button></div>
            </form>
        </details>
    <?php endif; ?>

    <p class="hint"><a href="/login">Start again</a></p>
</div>
<?php if ($this->m_hasSecurityKeys): ?>
<script type="module" src="/js/SecondFactorPage.js"></script>
<?php endif; ?>
<?php
    }


    // What the account's second factors are, e.g. "authenticator app or security key".
    private function describeFactors() : string
    {
        $factors = [];

        if ($this->m_hasTotp)
        {
            $factors[] = 'authenticator app';
        }

        if ($this->m_hasSecurityKeys)
        {
            $factors[] = 'security key';
        }

        return (count($factors) === 0) ? 'second factor' : implode(' or ', $factors);
    }
}
