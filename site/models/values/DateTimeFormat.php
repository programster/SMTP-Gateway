<?php

/*
 * How dates and times are shown in the web interface (set on the General settings page): the order of a date's parts,
 * what separates them, and whether the time comes before or after the date. Times are always shown with a 24-hour
 * clock, e.g. "21:41:12 18-11-2026".
 */

declare(strict_types = 1);


final readonly class DateTimeFormat
{
    private DateOrder $m_order;
    private DateSeparator $m_separator;
    private TimePosition $m_timePosition;


    public function __construct(DateOrder $order, DateSeparator $separator, TimePosition $timePosition)
    {
        $this->m_order = $order;
        $this->m_separator = $separator;
        $this->m_timePosition = $timePosition;
    }


    /**
     * Create the format used when none is set: as in the UK, with dashes, and the time first, e.g.
     * "21:41:12 18-11-2026".
     *
     * @return DateTimeFormat
     */
    public static function createDefault() : DateTimeFormat
    {
        return new DateTimeFormat(DateOrder::DAY_MONTH_YEAR, DateSeparator::DASH, TimePosition::BEFORE_DATE);
    }


    /**
     * Format a time, in whichever time zone it's in.
     *
     * @param DateTimeImmutable $time - the time.
     *
     * @param string $fraction - digits of a fraction of a second to show after the seconds (e.g. "1234" for the
     * logs), or an empty string for none.
     *
     * @return string - e.g. "21:41:12 18-11-2026", or "21:41:12.1234 18-11-2026".
     */
    public function format(DateTimeImmutable $time, string $fraction) : string
    {
        $clock = $time->format('H:i:s') . (($fraction === '') ? '' : ".{$fraction}");
        $date = $this->formatDate($time);
        return ($this->m_timePosition === TimePosition::BEFORE_DATE) ? "{$clock} {$date}" : "{$date} {$clock}";
    }


    public function formatDate(DateTimeImmutable $time) : string
    {
        return $time->format($this->m_order->getPhpFormat($this->m_separator));
    }


    # Accessors
    public function getOrder() : DateOrder { return $this->m_order; }
    public function getSeparator() : DateSeparator { return $this->m_separator; }
    public function getTimePosition() : TimePosition { return $this->m_timePosition; }
}
