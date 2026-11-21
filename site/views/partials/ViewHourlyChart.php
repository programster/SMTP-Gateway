<?php

/*
 * A bar chart of how many emails were accepted in each of the last 24 hours, with the part that had to wait in the
 * queue shown separately, a vertical axis of round numbers, and a line across the chart at each. The bars are SVG,
 * whose sizes are attributes, so they can line up exactly with the axis without the inline styles the CSP forbids. It
 * needs no JavaScript.
 */

declare(strict_types = 1);


class ViewHourlyChart extends Programster\AbstractView\AbstractView
{
    private const int BAR_SLOT_WIDTH = 10;
    private const int MAX_AXIS_INTERVALS = 5;
    private const float MIN_VISIBLE_HEIGHT = 1.5;

    private readonly HourlyVolumeCollection $m_volumes;


    public function __construct(HourlyVolumeCollection $volumes)
    {
        $this->m_volumes = clone $volumes;
    }


    protected function renderContent() : void
    {
        $peak = 0;

        foreach ($this->m_volumes as $volume)
        {
            /* @var $volume HourlyVolume */
            $peak = max($peak, $volume->getAccepted());
        }

        // An empty chart still needs an axis to draw.
        $max = max(1, $peak);
        $step = self::getAxisStep($max);
        $intervals = (int)ceil($max / $step);
        $top = $step * $intervals;
        $width = count($this->m_volumes) * self::BAR_SLOT_WIDTH;
        $timezone = Settings::get()->getTimezone();
?>
<figure class="chart">
    <figcaption>Emails accepted per hour, last 24 hours (peak: <?= number_format($peak) ?>). <span
        class="legend-queued">Darker</span> = had to wait in the queue.</figcaption>
    <div class="chart-plot">
        <div class="chart-axis" aria-hidden="true">
            <?php for ($tick = $intervals; $tick >= 0; $tick--): ?>
                <span><?= number_format($tick * $step) ?></span>
            <?php endfor; ?>
        </div>
        <div class="chart-area">
            <svg class="chart-svg" viewBox="0 0 <?= $width ?> 100" preserveAspectRatio="none" role="img"
                aria-label="Emails accepted per hour, last 24 hours">
                <?php for ($tick = 0; $tick <= $intervals; $tick++): ?>
                    <?php $lineY = round(100 * $tick / $intervals, 3); ?>
                    <line class="<?= ($tick === $intervals) ? 'chart-baseline' : 'chart-gridline' ?>" x1="0"
                        x2="<?= $width ?>" y1="<?= $lineY ?>" y2="<?= $lineY ?>"
                        vector-effect="non-scaling-stroke"></line>
                <?php endfor; ?>
                <?php foreach ($this->m_volumes as $index => $volume): ?>
                    <?php
                        /* @var $volume HourlyVolume */
                        $x = $index * self::BAR_SLOT_WIDTH;
                        $sentHeight = self::toHeight($volume->getAccepted() - $volume->getQueued(), $top);
                        $queuedHeight = self::toHeight($volume->getQueued(), $top);
                        $hour = $volume->getHourStart()->setTimezone($timezone)->format('H:i');
                        $label = "{$hour}: {$volume->getAccepted()} accepted, {$volume->getQueued()} queued";
                    ?>
                    <g class="chart-hour">
                        <title><?= Html::e($label) ?></title>
                        <rect class="chart-hit" x="<?= $x ?>" y="0" width="<?= self::BAR_SLOT_WIDTH ?>"
                            height="100"></rect>
                        <rect class="chart-bar-sent" x="<?= $x + 1 ?>" y="<?= 100 - $sentHeight ?>" width="8"
                            height="<?= $sentHeight ?>"></rect>
                        <rect class="chart-bar-queued" x="<?= $x + 1 ?>" y="<?= 100 - $sentHeight - $queuedHeight ?>"
                            width="8" height="<?= $queuedHeight ?>"></rect>
                    </g>
                <?php endforeach; ?>
            </svg>
            <div class="chart-hours" aria-hidden="true">
                <?php foreach ($this->m_volumes as $volume): ?>
                    <span><?= Html::e($volume->getHourStart()->setTimezone($timezone)->format('H:i')) ?></span>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</figure>
<?php
    }


    /**
     * Get the gap between the axis's numbers: the smallest of 1, 2, 5, 10, 20, 50 and so on that reaches the peak
     * within a few lines.
     *
     * @param int $max - the largest value in the chart.
     *
     * @return int
     */
    private static function getAxisStep(int $max) : int
    {
        $multipliers = [1, 2, 5];
        $index = 0;
        $magnitude = 1;
        $step = 1;

        while ($step * self::MAX_AXIS_INTERVALS < $max)
        {
            $index = ($index + 1) % count($multipliers);
            $magnitude = ($index === 0) ? $magnitude * 10 : $magnitude;
            $step = $multipliers[$index] * $magnitude;
        }

        return $step;
    }


    /**
     * Get a bar's height, out of 100. Anything above zero is tall enough to still be seen beside a much busier hour.
     *
     * @param int $value - the bar's value.
     *
     * @param int $top - the value at the top of the axis.
     *
     * @return float
     */
    private static function toHeight(int $value, int $top) : float
    {
        return ($value <= 0) ? 0.0 : max(self::MIN_VISIBLE_HEIGHT, round(100 * $value / $top, 3));
    }
}
