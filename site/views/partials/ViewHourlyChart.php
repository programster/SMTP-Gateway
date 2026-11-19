<?php

/*
 * A bar chart of how many emails were accepted in each of the last 24 hours, with the part that had to wait in the
 * queue shown separately. Drawn with plain HTML and CSS, so it needs no JavaScript.
 */

declare(strict_types = 1);


class ViewHourlyChart extends Programster\AbstractView\AbstractView
{
    private readonly HourlyVolumeCollection $m_volumes;


    public function __construct(HourlyVolumeCollection $volumes)
    {
        $this->m_volumes = clone $volumes;
    }


    protected function renderContent() : void
    {
        $max = 1;

        foreach ($this->m_volumes as $volume)
        {
            /* @var $volume HourlyVolume */
            $max = max($max, $volume->getAccepted());
        }
?>
<figure class="chart">
    <figcaption>Emails accepted per hour, last 24 hours (peak: <?= number_format($max) ?>). <span
        class="legend-queued">Darker</span> = had to wait in the queue.</figcaption>
    <div class="chart-bars">
        <?php foreach ($this->m_volumes as $volume): ?>
            <?php
                /* @var $volume HourlyVolume */
                $sentNowHeight = self::toHeightStep($volume->getAccepted() - $volume->getQueued(), $max);
                $queuedHeight = self::toHeightStep($volume->getQueued(), $max);
                $hour = $volume->getHourStart()->setTimezone(Settings::get()->getTimezone())->format('H:i');
                $label = "{$hour}: {$volume->getAccepted()} accepted, {$volume->getQueued()} queued";
            ?>
            <div class="chart-bar" title="<?= Html::e($label) ?>">
                <span class="chart-bar-queued h-<?= $queuedHeight ?>"></span>
                <span class="chart-bar-sent h-<?= $sentNowHeight ?>"></span>
                <span class="chart-bar-label"><?= Html::e($hour) ?></span>
            </div>
        <?php endforeach; ?>
    </div>
</figure>
<?php
    }


    /**
     * Get a bar's height as a percentage of the chart, in steps of 5 (as the CSP doesn't allow inline styles, the
     * heights are classes, h-0 to h-100). Anything above zero is at least one step, so it's still visible.
     *
     * @param int $value - the bar's value.
     *
     * @param int $max - the largest value in the chart.
     *
     * @return int
     */
    private static function toHeightStep(int $value, int $max) : int
    {
        return ($value <= 0) ? 0 : (int)max(5, 5 * round(20 * $value / $max));
    }
}
