<?php

/*
 * The accounts that can log in to the web interface, on two tabs: the live ones (active and blocked, the default), each
 * with buttons to block or unblock it and to archive it, and the archived ones, each with buttons to restore or delete
 * it. Archiving and deleting each ask for your password, in a dialog that says what will happen. An account can only be
 * deleted once it's archived, so deleting always takes two steps.
 */

declare(strict_types = 1);


class ViewUsersPage extends Programster\AbstractView\AbstractView
{
    private readonly AdminUserSummaryCollection $m_users;
    private readonly AdminUserCollection $m_archived;
    private readonly bool $m_isShowingArchived;
    private readonly AdminUser $m_currentUser;
    private readonly NewAccountForm $m_form;
    private readonly string $m_error;


    /**
     * @param AdminUserSummaryCollection $users - the live accounts (active and blocked).
     *
     * @param AdminUserCollection $archived - the archived accounts.
     *
     * @param bool $isShowingArchived - whether the Archived tab is the one open.
     *
     * @param AdminUser $currentUser - who is logged in (who can't block, archive or delete themselves).
     *
     * @param NewAccountForm $form - what to fill the new account form with.
     *
     * @param string $error - why the last action failed, or an empty string.
     */
    public function __construct(
        AdminUserSummaryCollection $users,
        AdminUserCollection $archived,
        bool $isShowingArchived,
        AdminUser $currentUser,
        NewAccountForm $form,
        string $error
    )
    {
        $this->m_users = clone $users;
        $this->m_archived = clone $archived;
        $this->m_isShowingArchived = $isShowingArchived;
        $this->m_currentUser = $currentUser;
        $this->m_form = $form;
        $this->m_error = $error;
    }


    protected function renderContent() : void
    {
?>
<h1>Users</h1>
<p>Everyone listed here who isn't blocked can log in to the web interface, and has full control of the service.</p>
<?= new ViewFormError($this->m_error) ?>
<?= new ViewListTabs(
    '/admin/users',
    'Archived',
    'archived',
    count($this->m_users),
    count($this->m_archived),
    $this->m_isShowingArchived
) ?>
<?php if ($this->m_isShowingArchived): ?>
    <?php $this->renderArchived(); ?>
<?php else: ?>
    <?php $this->renderLive(); ?>
<?php endif; ?>

<h2>Add an account</h2>
<form method="post" action="/admin/users" class="card" id="new-account-form">
    <?= new ViewCsrfField() ?>
    <?= new ViewAccountFields($this->m_form) ?>
    <div class="form-actions"><button type="submit">Add account</button></div>
</form>
<?= new ViewAccountActionDialog(AccountAction::ARCHIVE) ?>
<?php if ($this->m_isShowingArchived): ?>
    <?= new ViewAccountActionDialog(AccountAction::DELETE) ?>
<?php endif; ?>
<script type="module" src="/js/UsersPage.js"></script>
<?php
    }


    // The live accounts (active and blocked), each with its buttons.
    private function renderLive() : void
    {
?>
<div class="table-wrap">
<table>
    <thead><tr><th>Email address</th><th>Created</th><th>Last login</th><th>Authenticator app</th><th>Security
        keys</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($this->m_users as $summary): ?>
        <?php /* @var $summary AdminUserSummary */ $user = $summary->getUser(); ?>
        <?php $isYou = ($user->getId() === $this->m_currentUser->getId()); ?>
        <?php $isBlocked = ($user->getStatus() === AccountStatus::BLOCKED); ?>
        <tr>
            <td><a href="/admin/users/<?= Html::e(rawurlencode($user->getId())) ?>"><?= Html::e($user->getEmail())
                ?></a><?= $isYou ? ' <span class="badge">you</span>' : '' ?><?= $isBlocked
                ? ' <span class="badge badge-blocked">blocked</span>'
                : '' ?></td>
            <td><?= Html::e(Time::format($user->getCreatedAt())) ?></td>
            <td><?= new ViewLastUse($user->getLastLoginAt(), $user->getLastLoginIp()) ?></td>
            <td><?= $user->hasTotp() ? 'Yes' : '<span class="muted">No</span>' ?></td>
            <td><?= $summary->getSecurityKeyCount() ?></td>
            <td class="row-actions"><?php if ($isYou === false): ?><?php $this->renderLiveActions($user); ?><?php
                endif; ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
<?php
    }


    // A live account's buttons: block or unblock it, and archive it.
    private function renderLiveActions(AdminUser $user) : void
    {
        $email = $user->getEmail();
        $isBlocked = ($user->getStatus() === AccountStatus::BLOCKED);
        $statusAction = $isBlocked ? 'unblock' : 'block';
        $statusPath = '/admin/users/' . rawurlencode($user->getId()) . '/' . $statusAction;

        $statusTitle = $isBlocked
            ? 'Unblock user: let them log in again'
            : 'Block user: stop them logging in, and log them out now, until they\'re unblocked';

        $archiveTitle =
            'Archive user: remove their email address and sign-in details, keeping only their ID. This is the first'
            . ' step to deleting an account.';
?>
<form method="post" action="<?= Html::e($statusPath) ?>" class="inline-form">
    <?= new ViewCsrfField() ?>
    <button type="submit" class="icon-button neutral" title="<?= Html::e($statusTitle) ?>"
        aria-label="<?= Html::e(ucfirst($statusAction) . ' ' . $email) ?>"><?= new ViewIcon(
            $isBlocked ? Icon::UNBLOCK : Icon::BLOCK
        ) ?></button>
</form>
<button type="button" class="icon-button" data-role="archive-account"
    data-action="<?= Html::e(AccountAction::ARCHIVE->getPath($user->getId())) ?>" data-name="<?= Html::e($email) ?>"
    title="<?= Html::e($archiveTitle) ?>"
    aria-label="Archive <?= Html::e($email) ?>"><?= new ViewIcon(Icon::ARCHIVE) ?></button>
<?php
    }


    // The archived accounts, each with buttons to restore it and delete it.
    private function renderArchived() : void
    {
?>
<?php if (count($this->m_archived) === 0): ?>
    <p class="muted">No accounts are archived.</p>
<?php else: ?>
<p>These can't log in, and have no email address or sign-in details. Restore one to use it again, with its email
    address entered again, or delete it for good.</p>
<div class="table-wrap">
<table>
    <thead><tr><th>Account ID</th><th>Archived</th><th>Created</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($this->m_archived as $user): ?>
        <?php /* @var $user AdminUser */ $id = $user->getId(); ?>
        <tr>
            <td><a class="mono" href="/admin/users/<?= Html::e(rawurlencode($id)) ?>"><?= Html::e($id) ?></a></td>
            <td><?= Html::e(Time::format((string)$user->getStatusChangedAt())) ?></td>
            <td><?= Html::e(Time::format($user->getCreatedAt())) ?></td>
            <td class="row-actions">
                <a class="icon-button neutral" href="/admin/users/<?= Html::e(rawurlencode($id)) ?>/restore"
                    title="Restore: enter its email address again" aria-label="Restore account <?= Html::e($id)
                    ?>"><?= new ViewIcon(Icon::RESTORE) ?></a>
                <button type="button" class="icon-button" data-role="delete-account"
                    data-action="<?= Html::e(AccountAction::DELETE->getPath($id)) ?>"
                    data-name="<?= Html::e("account {$id}") ?>" title="Delete for good"
                    aria-label="Delete account <?= Html::e($id) ?>"><?= new ViewIcon(Icon::TRASH) ?></button>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
<?php endif; ?>
<?php
    }
}
