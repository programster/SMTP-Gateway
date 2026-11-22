<?php

/*
 * One email token: its restrictions, how much it has sent, and its recent emails.
 */

declare(strict_types = 1);


class ViewEmailTokenPage extends Programster\AbstractView\AbstractView
{
    public const string SENDER_CHECKS_INTRO =
        'Whether email from each of the token\'s allowed senders will be accepted and trusted: the domain\'s own DNS'
        . ' (DMARC, SPF), and whether each enabled provider it may use has it verified. Fix any problems before the'
        . ' token is used, or its email may be refused or go to spam.';

    private readonly EmailToken $m_emailToken;
    private readonly EmailTokenPolicy $m_policy;
    private readonly ActivityCounts $m_counts;
    private readonly HourlyVolumeCollection $m_hourly;
    private readonly EmailMessageCollection $m_messages;
    private readonly ProviderRestrictionSummary $m_providers;


    public function __construct(
        EmailToken $emailToken,
        EmailTokenPolicy $policy,
        ProviderRestrictionSummary $providers,
        ActivityCounts $counts,
        HourlyVolumeCollection $hourly,
        EmailMessageCollection $messages
    )
    {
        $this->m_emailToken = $emailToken;
        $this->m_policy = $policy;
        $this->m_providers = $providers;
        $this->m_counts = $counts;
        $this->m_hourly = clone $hourly;
        $this->m_messages = clone $messages;
    }


    protected function renderContent() : void
    {
        $emailToken = $this->m_emailToken;
        $policy = $this->m_policy;

        $restrictions = [
            'Allowed IP addresses' => $policy->getAllowedNetworks()->describe(),
            AddressRuleField::SENDER->getLabel() => $policy->getAllowedSenders()->describe(),
            AddressRuleField::REPLY_TO->getLabel() => $policy->getAllowedReplyTos()->describe(),
            AddressRuleField::RECIPIENT->getLabel() => $policy->getAllowedRecipients()->describe(),
            'Providers' => $this->m_providers->getDescription(),
        ];

        $emailTokens = new EmailTokenCollection($emailToken);
        $editPath = '/email-tokens/' . rawurlencode((string)$emailToken->getId()) . '/edit';
?>
<h1><?= Html::e($emailToken->getName()) ?>
    <?= $emailToken->isRevoked() ? '<span class="badge badge-revoked">revoked</span>' : '' ?></h1>
<?php if ($emailToken->isRevoked() === false && $this->m_providers->getWarning() !== ''): ?>
    <p class="flash flash-warning" role="status"><?= Html::e($this->m_providers->getWarning()) ?></p>
<?php endif; ?>
<div class="card">
    <p>ID (the SMTP username): <code><?= Html::e($emailToken->getId()) ?></code></p>
    <p class="muted">
        Created <?= Html::e(Time::format($emailToken->getCreatedAt())) ?>.
        <?php $lastUsed = $emailToken->getLastUsedAt(); ?>
        Last used: <?= Html::e(($lastUsed === null) ? 'never' : Time::format($lastUsed)) ?><?php
            if ($emailToken->getLastUsedIp() !== null): ?>, from
            <span class="mono"><?= Html::e($emailToken->getLastUsedIp()) ?></span><?php endif; ?>.
        <?php if ($emailToken->isRevoked()): ?>Revoked
            <?= Html::e(Time::format((int)$emailToken->getRevokedAt())) ?>.<?php endif; ?>
    </p>
    <div class="grid-2">
        <?php foreach ($restrictions as $label => $text): ?>
            <div><h3><?= Html::e($label) ?></h3><pre><?= Html::e($text) ?></pre></div>
        <?php endforeach; ?>
        <div><h3>Rate limits</h3><p><?= Html::e($policy->getRateLimits()->describe()) ?></p></div>
        <div><h3>Priority</h3><p><?= $policy->getPriority()->getValue() ?></p></div>
    </div>
    <?php if ($emailToken->isRevoked() === false): ?>
        <div class="form-actions">
            <a class="button" href="<?= Html::e($editPath) ?>"><?= new ViewIcon(Icon::EDIT) ?> Edit</a>
            <button type="button" class="danger" data-role="revoke-email-token">Revoke this email token</button>
            <span class="hint">It stops working straight away, and any of its emails still in the queue are cancelled.
                This can't be undone.</span>
        </div>
    <?php endif; ?>
</div>
<?php if ($emailToken->isRevoked() === false): ?>
    <?= new ViewRevokeEmailTokenDialog($emailToken) ?>
    <?= new ViewSenderChecks("/email-tokens/{$emailToken->getId()}/sender-checks", self::SENDER_CHECKS_INTRO) ?>
<?php endif; ?>
<?= new ViewActivityCounts($this->m_counts, $this->m_emailToken->getId()) ?>
<?= new ViewHourlyChart($this->m_hourly) ?>
<h2>Recent emails</h2>
<?= new ViewMessagesTable($this->m_messages, $emailTokens) ?>
<p><a href="/messages?email_token=<?= Html::e($emailToken->getId()) ?>">All of this email token's emails</a></p>
<?php if ($emailToken->isRevoked() === false): ?>
    <script type="module" src="/js/EmailTokenPage.js"></script>
<?php endif; ?>
<?php
    }
}
