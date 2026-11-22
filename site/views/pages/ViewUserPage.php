<?php

/*
 * One account: its status, when it was created, when it last logged in (and from where), its second factors, and a
 * link to the logs about it. A live account has a form to change its email address, a section for recovering access
 * (a grace period for a lost second factor, and the commands to reset it), and buttons to block or unblock and archive
 * it. An archived one has buttons to restore and delete it.
 */

declare(strict_types = 1);


class ViewUserPage extends Programster\AbstractView\AbstractView
{
    private readonly AdminUserSummary $m_summary;
    private readonly bool $m_isCurrentUser;
    private readonly string $m_email;
    private readonly string $m_error;


    /**
     * @param AdminUserSummary $summary - the account, how many security keys it has, and how many backup codes it has
     * left.
     *
     * @param bool $isCurrentUser - whether it's the account logged in now, which can't block, archive or delete itself.
     *
     * @param string $email - the email address field's value: the account's, or what was entered when changing it.
     *
     * @param string $error - why changing it failed, or an empty string.
     */
    public function __construct(AdminUserSummary $summary, bool $isCurrentUser, string $email, string $error)
    {
        $this->m_summary = $summary;
        $this->m_isCurrentUser = $isCurrentUser;
        $this->m_email = $email;
        $this->m_error = $error;
    }


    protected function renderContent() : void
    {
        $user = $this->m_summary->getUser();
        $id = $user->getId();
        $path = '/admin/users/' . rawurlencode($id);
        $keyCount = $this->m_summary->getSecurityKeyCount();
        $status = $user->getStatus();
        $isArchived = ($status === AccountStatus::ARCHIVED);
        $logsPath = '/admin/logs?' . http_build_query(['context_name' => 'user_id', 'context_value' => $id]);
?>
<p><a href="/admin/users<?= $isArchived ? '?archived=1' : '' ?>">Back to the users</a></p>
<h1><?= Html::e($user->describe()) ?><?= $this->m_isCurrentUser ? ' <span class="badge">you</span>' : '' ?><?=
    ($status === AccountStatus::ACTIVE) ? '' : ' <span class="badge badge-blocked">' . Html::e(
        strtolower($status->getLabel())
    ) . '</span>' ?></h1>
<?= new ViewFormError($this->m_error) ?>
<div class="card">
    <dl class="details">
        <dt>Account ID</dt><dd class="mono"><?= Html::e($id) ?></dd>
        <dt>Status</dt><dd><?= Html::e($status->getLabel()) ?><?= ($user->getStatusChangedAt() === null)
            ? ''
            : ', since ' . Html::e(Time::format($user->getStatusChangedAt())) ?></dd>
        <dt>Created</dt><dd><?= Html::e(Time::format($user->getCreatedAt())) ?></dd>
        <dt>Last login</dt><dd><?= new ViewLastUse($user->getLastLoginAt(), $user->getLastLoginIp()) ?></dd>
        <?php if ($isArchived === false): ?>
            <dt>Authenticator app</dt><dd><?= $user->hasTotp() ? 'Yes' : '<span class="muted">No</span>' ?></dd>
            <dt>Security keys</dt><dd><?= $keyCount ?></dd>
            <dt>Backup codes left</dt><dd><?= $this->m_summary->getUnusedBackupCodeCount() ?></dd>
        <?php endif; ?>
    </dl>
    <p><a href="<?= Html::e($logsPath) ?>">Logs about this account</a></p>
</div>
<?php if ($this->m_isCurrentUser): ?>
    <p>To change your password or second factors, go to <a href="/account">My account</a>.</p>
<?php endif; ?>

<?php if ($isArchived): ?>
    <h2>Archived</h2>
    <div class="card">
        <p>This account can't log in, and its email address and sign-in details have been removed. Only its ID is
            kept, which is what the logs name it by.</p>
        <div class="form-actions">
            <a class="button" href="<?= Html::e($path) ?>/restore"><?= new ViewIcon(Icon::RESTORE) ?> Restore user</a>
            <button type="button" class="danger" data-role="delete-account"
                data-action="<?= Html::e(AccountAction::DELETE->getPath($id)) ?>"
                data-name="<?= Html::e("account {$id}") ?>"><?= new ViewIcon(Icon::TRASH) ?> Delete user</button>
        </div>
    </div>
    <?= new ViewAccountActionDialog(AccountAction::DELETE) ?>
<?php else: ?>
    <h2>Email address</h2>
    <form method="post" action="<?= Html::e("{$path}/email") ?>" class="card">
        <?= new ViewCsrfField() ?>
        <label for="email">Email address</label>
        <input type="email" id="email" name="email" value="<?= Html::e($this->m_email) ?>" required
            autocomplete="off">
        <p class="hint">What the account logs in with, and where password reset links are sent.</p>
        <div class="form-actions"><button type="submit">Change the email address</button></div>
    </form>
    <?php if ($this->m_isCurrentUser === false): ?>
        <?php $this->renderPasswordReset($user, $path); ?>
        <?php $this->renderRecovery($user, $path); ?>
        <?php $this->renderLiveActions($user, $path); ?>
    <?php endif; ?>
<?php endif; ?>
<script type="module" src="/js/UserPage.js"></script>
<?php
    }


    // Emailing the account a link to choose a new password.
    private function renderPasswordReset(AdminUser $user, string $path) : void
    {
        $canSend = Settings::get()->canSendPasswordResets();
        $isActive = ($user->getStatus() === AccountStatus::ACTIVE);
?>
<h2>Password reset</h2>
<div class="card">
    <?php if ($isActive === false): ?>
        <p class="muted">Unblock this account to change its password.</p>
    <?php else: ?>
        <div class="account-action">
            <p>Email them a link to choose a new password. It works once, for an hour.</p>
            <form method="post" action="<?= Html::e("{$path}/password-reset") ?>" class="inline-form">
                <?= new ViewCsrfField() ?>
                <button type="submit" class="secondary"<?= $canSend ? '' : ' disabled' ?>>Send password reset
                    email</button>
            </form>
            <?php if ($canSend === false): ?>
                <p class="hint">This needs <code>PUBLIC_URL</code>, and the address the service's own emails come from
                    (in the Sending part of Settings &gt; General).</p>
            <?php endif; ?>
        </div>
        <div class="account-action">
            <p>Or generate a random password to give them yourself. This replaces their password straight away, logs
                them out, and makes them choose their own when they next log in.</p>
            <form method="post" action="<?= Html::e("{$path}/temporary-password") ?>" class="inline-form">
                <?= new ViewCsrfField() ?>
                <button type="submit" class="secondary">Set temporary password</button>
            </form>
        </div>
        <p class="hint account-password-note">They'll still need their second factor to log in. If they've lost that
            too, change the password first, then give them a grace period, as changing a password cancels one.</p>
    <?php endif; ?>
</div>
<?php
    }


    // Getting someone back in: a grace period for a lost second factor, and the command for the server.
    private function renderRecovery(AdminUser $user, string $path) : void
    {
        $email = $user->getEmail();
        $hasSecondFactor = ($user->hasTotp() || $this->m_summary->getSecurityKeyCount() > 0);
        $backupCodes = $this->m_summary->getUnusedBackupCodeCount();
        $isActive = ($user->getStatus() === AccountStatus::ACTIVE);
        $mfaCommand = 'docker compose exec -u www-data app php /var/www/site/scripts/reset-mfa.php '
            . escapeshellarg($email);
?>
<h2>Multi-factor authentication</h2>
<div class="card" id="account-recovery">
    <?php if ($hasSecondFactor === false): ?>
        <p>This account has no second factor, so there's nothing to recover.</p>
    <?php else: ?>
        <?php if ($backupCodes > 0): ?>
            <p>If they've lost their second factor, they can first try one of their <?= $backupCodes ?> unused backup
                <?= ($backupCodes === 1) ? 'code' : 'codes' ?> on the login page.</p>
        <?php endif; ?>
        <?php if ($user->hasActiveMfaGrace()): ?>
            <p class="flash flash-warning">A grace period is active until
                <?= Html::e(Time::format((int)$user->getMfaGraceUntil())) ?>. Their next password login gets in
                without a second factor, and replaces their old ones.</p>
            <form method="post" action="<?= Html::e("{$path}/mfa-grace/cancel") ?>" class="form-actions">
                <?= new ViewCsrfField() ?>
                <button type="submit" class="secondary">Cancel the grace period</button>
            </form>
        <?php elseif ($isActive): ?>
            <p>A grace period lets them log in with just their password for the next hour, once. That login removes
                their old authenticator app, security keys and backup codes, and takes them to set up a new one.</p>
            <div class="form-actions">
                <button type="button" class="danger" data-role="mfa-grace-account"
                    data-action="<?= Html::e(AccountAction::MFA_GRACE->getPath($user->getId())) ?>"
                    data-name="<?= Html::e($email) ?>"><?= Html::e(AccountAction::MFA_GRACE->getButtonLabel())
                    ?></button>
            </div>
            <?= new ViewAccountActionDialog(AccountAction::MFA_GRACE) ?>
        <?php else: ?>
            <p class="muted">Unblock this account to give it a grace period.</p>
        <?php endif; ?>
        <h3>Remove the second factors from the server</h3>
        <div class="secret-box"><input type="text" id="reset-mfa-command" value="<?= Html::e($mfaCommand) ?>"
            aria-label="Command to remove the second factors" readonly><button type="button" class="secondary"
            data-copy-target="reset-mfa-command">Copy</button></div>
    <?php endif; ?>
</div>
<?php
    }


    // Blocking or unblocking a live account, and archiving it, each on its own row with what it does above it.
    private function renderLiveActions(AdminUser $user, string $path) : void
    {
        $isBlocked = ($user->getStatus() === AccountStatus::BLOCKED);

        $blockText = $isBlocked
            ? 'Unblocking lets this user log in again.'
            : 'Blocking stops this user logging in, and logs them out now, until they\'re unblocked.';
?>
<h2>Block or archive</h2>
<div class="card">
    <div class="account-action">
        <p><?= Html::e($blockText) ?></p>
        <form method="post" action="<?= Html::e($path . ($isBlocked ? '/unblock' : '/block')) ?>" class="inline-form">
            <?= new ViewCsrfField() ?>
            <button type="submit" class="secondary"><?= new ViewIcon($isBlocked ? Icon::UNBLOCK : Icon::BLOCK) ?>
                <?= $isBlocked ? 'Unblock user' : 'Block user' ?></button>
        </form>
    </div>
    <div class="account-action">
        <p>Archiving removes this user's email address and sign-in details, keeping only their ID. It's the first
            step to deleting an account.</p>
        <button type="button" class="danger" data-role="archive-account"
            data-action="<?= Html::e(AccountAction::ARCHIVE->getPath($user->getId())) ?>"
            data-name="<?= Html::e($user->getEmail()) ?>"><?= new ViewIcon(Icon::ARCHIVE) ?> Archive user</button>
    </div>
</div>
<?= new ViewAccountActionDialog(AccountAction::ARCHIVE) ?>
<?php
    }
}
