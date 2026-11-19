<?php

declare(strict_types = 1);


class ViewEmailTokensPage extends Programster\AbstractView\AbstractView
{
    private readonly EmailTokenCollection $m_emailTokens;


    public function __construct(EmailTokenCollection $tokens)
    {
        $this->m_emailTokens = clone $tokens;
    }


    protected function renderContent() : void
    {
?>
<h1>Email tokens</h1>
<p>Each email token lets one of your services send email, through the SMTP server or the <code>/api/v1/send</code>
    endpoint, within the restrictions you give it. Only a hash of each token's secret is stored, so a token can't be
    shown again: if one is lost, create a new token and revoke the old one.</p>
<p><a class="button" href="/email-tokens/new">Create an email token</a></p>
<?php if (count($this->m_emailTokens) > 0): ?>
<div class="table-wrap">
<table>
    <thead><tr><th>Name</th><th>ID (the SMTP username)</th><th>Created</th><th>Last
        used</th><th>Status</th></tr></thead>
    <tbody>
    <?php foreach ($this->m_emailTokens as $emailToken): ?>
        <?php /* @var $emailToken EmailToken */ ?>
        <tr>
            <td><a href="/email-tokens/<?= Html::e($emailToken->getId()) ?>"><?= Html::e($emailToken->getName())
                ?></a></td>
            <td class="mono"><?= Html::e($emailToken->getId()) ?></td>
            <td><?= Html::e(Time::format($emailToken->getCreatedAt())) ?></td>
            <td><?= new ViewLastUse($emailToken->getLastUsedAt(), $emailToken->getLastUsedIp()) ?></td>
            <td><?= $emailToken->isRevoked()
                ? '<span class="badge badge-revoked">revoked</span>'
                : '<span class="badge badge-sent">active</span>' ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
<?php endif; ?>
<?php
    }
}
