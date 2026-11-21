<?php

/*
 * The email tokens, on two tabs: the active ones (the default), and the revoked ones, with when each was revoked and
 * how old it was then.
 */

declare(strict_types = 1);


class ViewEmailTokensPage extends Programster\AbstractView\AbstractView
{
    private readonly EmailTokenCollection $m_active;
    private readonly EmailTokenCollection $m_revoked;
    private readonly bool $m_isShowingRevoked;


    /**
     * @param EmailTokenCollection $tokens - every email token, active and revoked.
     *
     * @param bool $isShowingRevoked - whether the Revoked tab is the one open.
     */
    public function __construct(EmailTokenCollection $tokens, bool $isShowingRevoked)
    {
        $active = [];
        $revoked = [];

        foreach ($tokens as $emailToken)
        {
            /* @var $emailToken EmailToken */
            if ($emailToken->isRevoked())
            {
                $revoked[] = $emailToken;
            }
            else
            {
                $active[] = $emailToken;
            }
        }

        $this->m_active = new EmailTokenCollection(...$active);
        $this->m_revoked = new EmailTokenCollection(...$revoked);
        $this->m_isShowingRevoked = $isShowingRevoked;
    }


    protected function renderContent() : void
    {
?>
<h1>Email tokens</h1>
<p>Each email token lets one of your services send email, through the SMTP server or the <code>/api/v1/send</code>
    endpoint, within the restrictions you give it. Only a hash of each token's secret is stored, so a token can't be
    shown again: if one is lost, create a new token and revoke the old one.</p>
<p><a class="button" href="/email-tokens/new">Create an email token</a></p>
<?= new ViewListTabs(
    '/email-tokens',
    'Revoked',
    'revoked',
    count($this->m_active),
    count($this->m_revoked),
    $this->m_isShowingRevoked
) ?>
<?php
        if ($this->m_isShowingRevoked)
        {
            $this->renderRevoked();
        }
        else
        {
            $this->renderActive();
        }
    }


    private function renderActive() : void
    {
?>
<?php if (count($this->m_active) === 0): ?>
<p class="muted">No active email tokens.</p>
<?php else: ?>
<div class="table-wrap">
<table>
    <thead><tr><th>Name</th><th>ID (the SMTP username)</th><th>Created</th><th>Last used</th></tr></thead>
    <tbody>
    <?php foreach ($this->m_active as $emailToken): ?>
        <?php /* @var $emailToken EmailToken */ ?>
        <tr>
            <td><a href="/email-tokens/<?= Html::e($emailToken->getId()) ?>"><?= Html::e($emailToken->getName())
                ?></a></td>
            <td class="mono"><?= Html::e($emailToken->getId()) ?></td>
            <td><?= Html::e(Time::format($emailToken->getCreatedAt())) ?></td>
            <td><?= new ViewLastUse($emailToken->getLastUsedAt(), $emailToken->getLastUsedIp()) ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
<?php endif; ?>
<?php
    }


    private function renderRevoked() : void
    {
?>
<?php if (count($this->m_revoked) === 0): ?>
<p class="muted">No revoked email tokens.</p>
<?php else: ?>
<div class="table-wrap">
<table>
    <thead><tr><th>Name</th><th>ID (the SMTP username)</th><th>Created</th><th>Revoked</th><th>Age when revoked</th>
        <th>Last used</th></tr></thead>
    <tbody>
    <?php foreach ($this->m_revoked as $emailToken): ?>
        <?php /* @var $emailToken EmailToken */ $revokedAt = (string)$emailToken->getRevokedAt(); ?>
        <tr>
            <td><a href="/email-tokens/<?= Html::e($emailToken->getId()) ?>"><?= Html::e($emailToken->getName())
                ?></a></td>
            <td class="mono"><?= Html::e($emailToken->getId()) ?></td>
            <td><?= Html::e(Time::format($emailToken->getCreatedAt())) ?></td>
            <td><?= Html::e(Time::format($revokedAt)) ?></td>
            <td><?= new ViewRevokedAge($emailToken->getCreatedAt(), $revokedAt) ?></td>
            <td><?= new ViewLastUse($emailToken->getLastUsedAt(), $emailToken->getLastUsedIp()) ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
<?php endif; ?>
<?php
    }
}
