<?php

/*
 * The dashboard widget of the email tokens that have sent the most emails, over a period chosen with its links (the
 * last day, week, month or year). Each count links to the emails it counted. Emails are only counted while their
 * records are kept, so for a period longer than that, it says so.
 */

declare(strict_types = 1);


class ViewMostUsedTokensCard extends Programster\AbstractView\AbstractView
{
    public const string PERIOD_PARAM = 'usage';

    private readonly EmailTokenUsageCollection $m_usages;
    private readonly UsagePeriod $m_period;
    private readonly int $m_recordDays;


    /**
     * @param EmailTokenUsageCollection $usages - the most used, most first.
     *
     * @param UsagePeriod $period - the period they're counted over.
     *
     * @param int $recordDays - how many days email records are kept for.
     */
    public function __construct(EmailTokenUsageCollection $usages, UsagePeriod $period, int $recordDays)
    {
        $this->m_usages = $usages;
        $this->m_period = $period;
        $this->m_recordDays = $recordDays;
    }


    protected function renderContent() : void
    {
        $window = $this->m_period->getWindow();
?>
<section class="card widget" id="most-used-email-tokens">
    <h3><a href="/email-tokens">Most used email tokens</a></h3>
    <nav class="period-switch" aria-label="Period">
        <?php foreach (UsagePeriod::cases() as $period): ?>
            <?php $current = ($period === $this->m_period) ? ' aria-current="true"' : ''; ?>
            <a href="/?<?= self::PERIOD_PARAM ?>=<?= $period->value ?>#most-used-email-tokens"<?= $current
                ?>><?= Html::e($period->getLabel()) ?></a>
        <?php endforeach; ?>
    </nav>
    <?php if (count($this->m_usages) === 0): ?>
        <p class="muted">No emails were sent <?= Html::e(lcfirst($window->getLabel())) ?>.</p>
    <?php else: ?>
        <ol class="widget-list">
        <?php foreach ($this->m_usages as $usage): ?>
            <?php
                /* @var $usage EmailTokenUsage */
                $emailToken = $usage->getEmailToken();
                $query = http_build_query(['email_token' => $emailToken->getId(), 'within' => $window->value]);
            ?>
            <li>
                <a href="/email-tokens/<?= Html::e(rawurlencode($emailToken->getId())) ?>"><?= Html::e(
                    $emailToken->getName()
                ) ?></a>
                <a class="hint" href="/messages?<?= Html::e($query) ?>"><?= number_format($usage->getEmailCount())
                    ?> <?= ($usage->getEmailCount() === 1) ? 'email' : 'emails' ?></a>
            </li>
        <?php endforeach; ?>
        </ol>
    <?php endif; ?>
    <?php if ($this->m_period->getDays() > $this->m_recordDays): ?>
        <?php $retentionPath = SettingsSection::RETENTION->getPagePath(); ?>
        <p class="hint">Email records are kept for <?= $this->m_recordDays ?> days (see
            <a href="<?= Html::e($retentionPath) ?>">Retention</a>), so this only counts those.</p>
    <?php endif; ?>
</section>
<?php
    }
}
