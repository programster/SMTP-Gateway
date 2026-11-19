<?php

declare(strict_types = 1);


class ViewDashboardPage extends Programster\AbstractView\AbstractView
{
    private readonly ActivityCounts $m_overall;
    private readonly HourlyVolumeCollection $m_hourly;
    private readonly EmailTokenActivityCollection $m_tokenActivity;
    private readonly RateLimitSet $m_globalLimits;
    private readonly ProvidersOverview $m_providers;
    private readonly bool $m_userHasSecondFactor;
    private readonly DashboardUsage $m_usage;


    public function __construct(
        ActivityCounts $overall,
        HourlyVolumeCollection $hourly,
        EmailTokenActivityCollection $keyActivity,
        RateLimitSet $globalLimits,
        ProvidersOverview $providers,
        bool $userHasSecondFactor,
        DashboardUsage $usage
    )
    {
        $this->m_overall = $overall;
        $this->m_hourly = clone $hourly;
        $this->m_tokenActivity = clone $keyActivity;
        $this->m_globalLimits = $globalLimits;
        $this->m_providers = $providers;
        $this->m_userHasSecondFactor = $userHasSecondFactor;
        $this->m_usage = $usage;
    }


    protected function renderContent() : void
    {
        $usage = $this->m_usage;
?>
<h1>Dashboard</h1>
<?php if ($this->m_userHasSecondFactor === false): ?>
    <?php /* Only reachable when a second factor isn't required, as otherwise the account would be setting one up. */ ?>
    <p class="flash flash-error">Your account has no two-factor authentication. <a href="/account">Set it up</a> to
        protect it.</p>
<?php endif; ?>
<?php if ($this->m_providers->getState() === ProvidersState::NOT_CONFIGURED): ?>
    <p class="flash flash-warning">No providers have been set up yet, so nothing can be sent. Emails are queued until
        then. <a href="/providers">Add a provider</a>.</p>
<?php elseif ($this->m_providers->getState() === ProvidersState::INVALID): ?>
    <p class="flash flash-error">Nothing can be sent, as the providers aren't valid (emails are queued until they're
        fixed): <?= Html::e($this->m_providers->getProblem()) ?> <a href="/providers">Fix them</a>.</p>
<?php endif; ?>
<p class="muted">
<?php if ($this->m_providers->getState() === ProvidersState::USABLE): ?>
    Sending through <strong><?= Html::e(implode(', ', $this->m_providers->getEnabledNames())) ?></strong>
        (<?= Html::e($this->m_providers->getMode()) ?> mode, <a href="/providers">change</a>).
<?php endif; ?>
    Global rate limits: <strong><?= Html::e($this->m_globalLimits->describe()) ?></strong> (<a
        href="/rate-limits">change</a>).
</p>
<?= new ViewActivityCounts($this->m_overall, '') ?>
<?= new ViewHourlyChart($this->m_hourly) ?>

<h2>Recent use</h2>
<div class="widgets">
    <?= new ViewRecentUseCard('Recent logins', '/admin/users', 'Nobody has logged in yet.', $usage->getLogins()) ?>
    <?= new ViewRecentUseCard(
        'API keys last used',
        '/api-keys',
        'No API key has been used yet.',
        $usage->getApiKeys()
    ) ?>
    <?= new ViewRecentUseCard(
        'Email tokens last used',
        '/email-tokens',
        'No email token has been used yet.',
        $usage->getEmailTokens()
    ) ?>
    <?= new ViewMostUsedTokensCard($usage->getMostUsed(), $usage->getPeriod(), $usage->getRecordDays()) ?>
</div>

<h2>Active email tokens</h2>
<?php if (count($this->m_tokenActivity) === 0): ?>
    <p class="muted">There are no active email tokens yet. <a href="/email-tokens/new">Create one</a>.</p>
<?php else: ?>
<div class="table-wrap">
<table>
    <thead><tr><th>Email token</th><th class="num">Last minute</th><th class="num">Last hour</th><th class="num">Last
        24h</th><th class="num">Queued</th><th class="num">Failed (24h)</th><th class="num">Rejected (24h)</th><th>Last
        used</th></tr></thead>
    <tbody>
    <?php foreach ($this->m_tokenActivity as $activity): ?>
        <?php
        /* @var $activity EmailTokenActivity */
        $emailToken = $activity->getEmailToken();
        $counts = $activity->getCounts();
        $lastUsed = $emailToken->getLastUsedAt();
        ?>
        <tr>
            <td><a href="/email-tokens/<?= Html::e($emailToken->getId()) ?>"><?= Html::e($emailToken->getName())
                ?></a></td>
            <td class="num"><?= number_format($counts->getLastMinute()) ?></td>
            <td class="num"><?= number_format($counts->getLastHour()) ?></td>
            <td class="num"><?= number_format($counts->getLast24Hours()) ?></td>
            <td class="num"><?= number_format($counts->getQueuedNow()) ?></td>
            <td class="num"><?= number_format($counts->getFailedLast24Hours()) ?></td>
            <td class="num"><?= number_format($counts->getRejectedLast24Hours()) ?></td>
            <td><?= Html::e(($lastUsed === null) ? 'Never' : Time::format($lastUsed)) ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
<?php endif; ?>
<?php
    }
}
