<?php

/*
 * One account: its status, when it was created, when it last logged in (and from where), its second factors, and a
 * link to the logs about it. A live account has a form to change its email address, and buttons to block or unblock
 * and archive it. An archived one has buttons to restore and delete it. Its own password and second factors are only
 * changed by the account itself, on its "My account" page.
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
            <a class="button" href="<?= Html::e($path) ?>/restore"><?= new ViewIcon(Icon::RESTORE) ?> Restore it</a>
            <button type="button" class="danger" data-role="delete-account"
                data-action="<?= Html::e(AccountAction::DELETE->getPath($id)) ?>"
                data-name="<?= Html::e("account {$id}") ?>"><?= new ViewIcon(Icon::TRASH) ?> Delete it</button>
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
        <?php $this->renderLiveActions($user, $path); ?>
    <?php endif; ?>
<?php endif; ?>
<script type="module" src="/js/UserPage.js"></script>
<?php
    }


    // Blocking or unblocking a live account, and archiving it.
    private function renderLiveActions(AdminUser $user, string $path) : void
    {
        $isBlocked = ($user->getStatus() === AccountStatus::BLOCKED);
?>
<h2>Block or archive</h2>
<div class="card">
    <p>Blocking stops the account logging in, and logs it out now, until it's unblocked. Archiving also removes its
        email address and sign-in details, keeping only its ID, and is the first step to deleting it.</p>
    <div class="form-actions">
        <form method="post" action="<?= Html::e($path . ($isBlocked ? '/unblock' : '/block')) ?>" class="inline-form">
            <?= new ViewCsrfField() ?>
            <button type="submit" class="secondary"><?= new ViewIcon($isBlocked ? Icon::UNBLOCK : Icon::BLOCK) ?>
                <?= $isBlocked ? 'Unblock it' : 'Block it' ?></button>
        </form>
        <button type="button" class="danger" data-role="archive-account"
            data-action="<?= Html::e(AccountAction::ARCHIVE->getPath($user->getId())) ?>"
            data-name="<?= Html::e($user->getEmail()) ?>"><?= new ViewIcon(Icon::ARCHIVE) ?> Archive it</button>
    </div>
</div>
<?= new ViewAccountActionDialog(AccountAction::ARCHIVE) ?>
<?php
    }
}
