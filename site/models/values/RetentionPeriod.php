<?php

/*
 * How long a part of each email is kept once the email has been sent, as set by settings.yaml's email_content_days or
 * email_attachment_days: -1 for not kept, 0 for as long as what it's part of is kept, or a number of days.
 * EmailRetentionPolicy puts them together, and checks they fit within each other.
 */

declare(strict_types = 1);


readonly class RetentionPeriod
{
    private RetentionMode $m_mode;
    private int $m_days;


    private function __construct(RetentionMode $mode, int $days)
    {
        $this->m_mode = $mode;
        $this->m_days = $days;
    }


    /**
     * Read a setting.
     *
     * @param string $setting - the setting's value: "-1", "0", or a number of days.
     *
     * @return RetentionPeriod
     *
     * @throws InvalidInputException - if it isn't -1, 0, or a whole number of days.
     */
    public static function createFromSetting(string $setting) : RetentionPeriod
    {
        $setting = trim($setting);

        if ($setting === '-1')
        {
            $period = new RetentionPeriod(RetentionMode::NOT_KEPT, 0);
        }
        elseif ($setting === '0')
        {
            $period = new RetentionPeriod(RetentionMode::KEPT_WITH_PARENT, 0);
        }
        elseif (preg_match('/^[1-9][0-9]{0,4}$/', $setting) === 1)
        {
            $period = new RetentionPeriod(RetentionMode::KEPT_FOR_DAYS, (int)$setting);
        }
        else
        {
            throw new InvalidInputException("It must be -1, 0, or a whole number of days, not \"{$setting}\".");
        }

        return $period;
    }


    /**
     * Work out how many days this keeps something for, given how long what it's part of is kept.
     *
     * @param int $parentDays - how many days what it's part of is kept, or -1 if it isn't kept.
     *
     * @return int - the number of days, or -1 if it isn't kept.
     */
    public function getEffectiveDays(int $parentDays) : int
    {
        return match ($this->m_mode) {
            RetentionMode::NOT_KEPT => -1,
            RetentionMode::KEPT_WITH_PARENT => $parentDays,
            RetentionMode::KEPT_FOR_DAYS => $this->m_days,
        };
    }


    /**
     * Get the period as its setting, the reverse of createFromSetting().
     * @return int - -1 (not kept), 0 (kept as long as what it's part of), or a number of days.
     */
    public function toSetting() : int
    {
        return match ($this->m_mode) {
            RetentionMode::NOT_KEPT => -1,
            RetentionMode::KEPT_WITH_PARENT => 0,
            RetentionMode::KEPT_FOR_DAYS => $this->m_days,
        };
    }


    # Accessors
    public function getMode() : RetentionMode { return $this->m_mode; }
    public function getDays() : int { return $this->m_days; }
}
