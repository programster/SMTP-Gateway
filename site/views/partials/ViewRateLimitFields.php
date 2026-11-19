<?php

/*
 * The rows of a form for entering rate limits: a number of emails (requests to the provider) or recipients, per
 * second, minute, hour, day, week or month (30 days). There's a row for each limit (or one empty row), and an "Add"
 * button, which adds another once the last is filled in (see DynamicRows.ts). Read back with
 * PolicyInput::readRateLimitsFromForm(). Rows left empty are ignored, so leaving them all empty means no limit.
 */

declare(strict_types = 1);


class ViewRateLimitFields extends Programster\AbstractView\AbstractView
{
    // A month is a sliding 30 days, like every other window, rather than a calendar month, so that a whole month's
    // allowance can't go out at the end of one month and again at the start of the next.
    public const array PERIODS = [
        1 => 'per second',
        60 => 'per minute',
        3600 => 'per hour',
        86400 => 'per day',
        604800 => 'per week',
        2592000 => 'per month (30 days)',
    ];

    private readonly RateLimitCollection $m_limits;


    /**
     * @param RateLimitCollection $limits - the limits to fill the rows with. Rows beyond these are left empty.
     */
    public function __construct(RateLimitCollection $limits)
    {
        $this->m_limits = clone $limits;
    }


    protected function renderContent() : void
    {
        $limits = $this->m_limits->toArray();
        $rowCount = max(1, count($limits));
?>
<div class="dynamic-rows" data-role="dynamic-rows" data-next-index="<?= $rowCount ?>"
    data-max-rows="<?= PolicyInput::MAX_FORM_ROWS ?>">
    <div data-role="rows">
    <?php for ($i = 0; $i < $rowCount; $i++): ?>
        <?php $this->renderRow((string)$i, $limits[$i] ?? null); ?>
    <?php endfor; ?>
    </div>
    <template data-role="row-template"><?php $this->renderRow(ViewValueRows::TEMPLATE_INDEX, null); ?></template>
    <button type="button" class="secondary" data-role="add-row">Add</button>
</div>
<?php
    }


    /**
     * Render one row: a limit's, or an empty one.
     *
     * @param string $index - its number, from 0, in its inputs' names, or ViewValueRows::TEMPLATE_INDEX for the
     * template that new rows are made from.
     *
     * @param RateLimit|null $limit - the limit, or null for an empty row. Only a row's own render takes null.
     *
     * @return void
     */
    private function renderRow(string $index, ?RateLimit $limit) : void
    {
        $max = ($limit === null) ? '' : (string)$limit->getMax();
        $unit = ($limit === null) ? RateLimitUnit::EMAILS : $limit->getUnit();
        $period = ($limit === null) ? 3600 : $limit->getPeriodSeconds();
        $periods = self::PERIODS;

        if (!isset($periods[$period]))
        {
            $periods[$period] = "per {$period} seconds";
        }
?>
<div class="dynamic-row rate-limit-row" data-role="row">
    <input type="number" min="1" name="rate_limit_max_<?= $index ?>" value="<?= Html::e($max) ?>"
        placeholder="e.g. 300" aria-label="Rate limit: how many" data-row-value>
    <select name="rate_limit_unit_<?= $index ?>" aria-label="Rate limit: what it counts">
        <?php foreach (RateLimitUnit::cases() as $case): ?>
            <option value="<?= Html::e($case->value) ?>"
                <?= ($case === $unit) ? 'selected' : '' ?>><?= Html::e($case->getLabel()) ?></option>
        <?php endforeach; ?>
    </select>
    <select name="rate_limit_period_<?= $index ?>" aria-label="Rate limit: period">
        <?php foreach ($periods as $seconds => $periodLabel): ?>
            <option value="<?= $seconds ?>"
                <?= ($seconds === $period) ? 'selected' : '' ?>><?= Html::e($periodLabel) ?></option>
        <?php endforeach; ?>
    </select>
    <button type="button" class="icon-button" data-role="remove-row" aria-label="Remove this rate limit"
        title="Remove"><?= new ViewIcon(Icon::TRASH) ?></button>
</div>
<?php
    }
}
