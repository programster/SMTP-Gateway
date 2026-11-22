<?php

/*
 * How old an API key is, always in days, so it's easy to tell when it's due to be replaced: yellow from 9 months
 * (WARNING_DAYS), as it approaches a year, and red from a year (DANGER_DAYS).
 */

declare(strict_types = 1);


class ViewKeyAge extends Programster\AbstractView\AbstractView
{
    public const int WARNING_DAYS = 274;
    public const int DANGER_DAYS = 365;

    private readonly int $m_days;


    /**
     * @param int $createdAt - when it was created (a Unix timestamp).
     */
    public function __construct(int $createdAt)
    {
        $this->m_days = Time::getDaysSince($createdAt);
    }


    protected function renderContent() : void
    {
        $level = match (true) {
            $this->m_days >= self::DANGER_DAYS => ' age-danger',
            $this->m_days >= self::WARNING_DAYS => ' age-warning',
            default => '',
        };

        $title = match (true) {
            $this->m_days >= self::DANGER_DAYS => 'A year or older: consider replacing it',
            $this->m_days >= self::WARNING_DAYS => 'Nearly a year old',
            default => '',
        };
?>
<span class="key-age<?= $level ?>"<?= ($title === '') ? '' : ' title="' . Html::e($title) . '"' ?>><?= number_format(
    $this->m_days
) ?> <?= ($this->m_days === 1) ? 'day' : 'days' ?></span>
<?php
    }
}
