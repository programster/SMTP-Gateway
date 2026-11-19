<?php

/*
 * The logged-in person's own account: their password, their second factors, and their backup codes.
 */

declare(strict_types = 1);


class ViewAccountPage extends Programster\AbstractView\AbstractView
{
    private readonly AdminUser $m_user;
    private readonly AdminWebauthnCredentialCollection $m_securityKeys;
    private readonly BackupCodeStatus $m_backupCodes;
    private readonly bool $m_isMfaRequired;
    private readonly string $m_error;


    /**
     * @param AdminUser $user - the logged-in account.
     *
     * @param AdminWebauthnCredentialCollection $securityKeys - its security keys.
     *
     * @param BackupCodeStatus $backupCodes - how many of its backup codes are unused.
     *
     * @param bool $isMfaRequired - whether two-factor authentication is required (set on the Settings page).
     *
     * @param string $error - why the last action failed, or an empty string.
     */
    public function __construct(
        AdminUser $user,
        AdminWebauthnCredentialCollection $securityKeys,
        BackupCodeStatus $backupCodes,
        bool $isMfaRequired,
        string $error
    )
    {
        $this->m_user = $user;
        $this->m_securityKeys = clone $securityKeys;
        $this->m_backupCodes = $backupCodes;
        $this->m_isMfaRequired = $isMfaRequired;
        $this->m_error = $error;
    }


    protected function renderContent() : void
    {
        $policy = AdminUserRepository::getPasswordPolicy();
        $minLength = $policy->getMinLength();
        $hasSecondFactor = ($this->m_user->hasTotp() || count($this->m_securityKeys) > 0);
?>
<h1>My account: <?= Html::e($this->m_user->getEmail()) ?></h1>
<?php if ($this->m_user->mustChangePassword()): ?>
    <p class="flash flash-error" role="alert">Your password was set by an administrator. <a
        href="#change-password">Choose a password of your own</a> to continue using the site.</p>
<?php elseif ($this->m_isMfaRequired && $hasSecondFactor === false): ?>
    <p class="flash flash-error" role="alert">Two-factor authentication is required. Set up an authenticator app or a
        security key below to continue using the site.</p>
<?php endif; ?>
<?= new ViewFormError($this->m_error) ?>

<div class="card">
    <h2>Two-factor authentication</h2>
    <p>With a second factor set up, logging in needs a code from your authenticator app, or your security key, as well
        as your password. You can set up both, and use either when you log
        in.<?= $this->m_isMfaRequired
            ? ' It\'s required for every account, so your last one can\'t be removed.'
            : '' ?></p>

    <h3>Authenticator app (TOTP)</h3>
    <?php if ($this->m_user->hasTotp()): ?>
        <p>An authenticator app is set up.</p>
        <form method="post" action="/account/totp/remove" class="form-actions">
            <?= new ViewCsrfField() ?>
            <input type="password" name="current_password" placeholder="Your current password"
                autocomplete="current-password" required aria-label="Your current password">
            <button type="submit" class="danger">Remove the authenticator app</button>
        </form>
    <?php else: ?>
        <p><a class="button" href="/account/totp">Set up an authenticator app</a></p>
    <?php endif; ?>

    <h3>Security keys (WebAuthn, e.g. YubiKey)</h3>
    <?php if (count($this->m_securityKeys) > 0): ?>
        <table>
            <thead><tr><th>Name</th><th>Added</th><th>Last used</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($this->m_securityKeys as $key): ?>
                <?php /* @var $key AdminWebauthnCredential */ ?>
                <tr>
                    <td><?= Html::e($key->getName()) ?></td>
                    <td><?= Html::e(Time::format($key->getCreatedAt())) ?></td>
                    <td><?= Html::e(
                        ($key->getLastUsedAt() === null) ? 'Never' : Time::format($key->getLastUsedAt())
                    ) ?></td>
                    <td class="row-actions">
                        <button type="button" class="icon-button" data-role="remove-security-key"
                            data-action="/account/security-keys/<?= Html::e($key->getId()) ?>/remove"
                            data-name="<?= Html::e($key->getName()) ?>"
                            aria-label="Remove <?= Html::e($key->getName()) ?>"
                            title="Remove"><?= new ViewIcon(Icon::TRASH) ?></button>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <dialog id="remove-security-key-dialog" aria-labelledby="remove-security-key-title">
            <form method="post" data-role="form">
                <?= new ViewCsrfField() ?>
                <h3 id="remove-security-key-title">Remove <span data-role="name"></span>?</h3>
                <p>It can't be used to log in once it's removed. Enter your password to remove it.</p>
                <label for="remove-security-key-password">Your password</label>
                <input type="password" id="remove-security-key-password" name="current_password"
                    autocomplete="current-password" required>
                <div class="form-actions">
                    <button type="submit" class="danger">Remove</button>
                    <button type="button" class="secondary" data-role="cancel">Cancel</button>
                </div>
            </form>
        </dialog>
    <?php else: ?>
        <p class="muted">No security keys yet.</p>
    <?php endif; ?>
    <div id="security-key-registration"
         data-options-url="/account/security-keys/options"
         data-register-url="/account/security-keys"
         data-csrf-token="<?= Html::e(WebSession::getCsrfToken()) ?>">
        <label for="security-key-name">Register a security key</label>
        <div class="secret-box">
            <input type="text" id="security-key-name" data-role="name" placeholder="A name for it, e.g. Blue YubiKey"
                maxlength="100">
            <button type="button" data-role="start">Register</button>
        </div>
        <p class="flash flash-error" data-role="error" hidden></p>
        <p class="hint">A security key only works on the hostname it was registered on, so register it at the address
            you normally use for this site. Browsers only allow security keys over HTTPS (or on localhost).</p>
    </div>

    <?php if ($hasSecondFactor): ?>
        <?php $this->renderBackupCodes(); ?>
    <?php endif; ?>
</div>

<div class="card" id="change-password">
    <h2>Change password</h2>
    <form method="post" action="/account/password">
        <?= new ViewCsrfField() ?>
        <label for="current_password">Current password</label>
        <input type="password" id="current_password" name="current_password" autocomplete="current-password" required>
        <label for="new_password">New password</label>
        <input type="password" id="new_password" name="new_password" autocomplete="new-password"
            minlength="<?= $minLength ?>" required>
        <label for="confirm_password">New password again</label>
        <input type="password" id="confirm_password" name="confirm_password" autocomplete="new-password"
            minlength="<?= $minLength ?>" required>
        <p class="hint"><?= Html::e($policy->describe()) ?></p>
        <div class="form-actions"><button type="submit">Change password</button></div>
    </form>
</div>
<script type="module" src="/js/AccountPage.js"></script>
<?php
    }


    // The backup codes: how many are left, and a form for generating new ones, which replace them.
    private function renderBackupCodes() : void
    {
        $status = $this->m_backupCodes;
        $numUnused = $status->getNumUnused();
        $total = BackupCodeService::COUNT;
        $isLow = $status->hasCodes() === false || $status->isRunningLow();
        $button = $status->hasCodes() ? 'Generate new backup codes' : 'Generate backup codes';
?>
    <h3 id="backup-codes">Backup codes</h3>
    <p>Single-use codes for logging in if you lose your authenticator app or security key. Each one works once,
        instead of your second factor.</p>
    <?php if ($status->hasCodes() === false): ?>
        <p class="flash flash-warning">You don't have any backup codes yet. Generate some, and keep them somewhere
            safe, so you can still log in if you lose your second factor.</p>
    <?php else: ?>
        <p<?= $isLow ? ' class="flash flash-warning"' : '' ?>><?= $numUnused ?> of <?= $total ?> unused, generated
            <?= Html::e(Time::format($status->getGeneratedAt())) ?>.<?= $isLow
                ? ' Generate new ones before you run out.'
                : '' ?></p>
    <?php endif; ?>
    <form method="post" action="/account/backup-codes" class="form-actions">
        <?= new ViewCsrfField() ?>
        <input type="password" name="current_password" placeholder="Your current password"
            autocomplete="current-password" required aria-label="Your current password">
        <button type="submit"<?= $isLow ? '' : ' class="secondary"' ?>><?= $button ?></button>
    </form>
    <?php if ($status->hasCodes()): ?>
        <p class="hint">This generates <?= $total ?> new codes, which are shown once. Your current codes stop working,
            whether or not they've been used.</p>
    <?php endif; ?>
<?php
    }
}
