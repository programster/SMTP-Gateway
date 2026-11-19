<?php

/*
 * Which page of the logs to show: in what order, how many to a page, and which page (from 1).
 */

declare(strict_types = 1);


final readonly class LogPage
{
    private LogSort $m_sort;
    private LogPageSize $m_size;
    private int $m_number;


    /**
     * @param LogSort $sort - the order they're in.
     *
     * @param LogPageSize $size - how many to a page.
     *
     * @param int $number - which page, from 1. One past the last page just shows no entries.
     *
     * @throws InvalidInputException - if the number is below 1.
     */
    public function __construct(LogSort $sort, LogPageSize $size, int $number)
    {
        if ($number < 1)
        {
            throw new InvalidInputException("There's no page {$number}: the first is page 1.");
        }

        $this->m_sort = $sort;
        $this->m_size = $size;
        $this->m_number = $number;
    }


    public static function createFirst() : LogPage
    {
        return new LogPage(LogSort::NEWEST_FIRST, LogPageSize::FIFTY, 1);
    }


    public function withNumber(int $number) : LogPage
    {
        return new LogPage($this->m_sort, $this->m_size, $number);
    }


    public function withSort(LogSort $sort) : LogPage
    {
        return new LogPage($sort, $this->m_size, 1);
    }


    public function getOffset() : int
    {
        return ($this->m_number - 1) * $this->m_size->value;
    }


    /**
     * Get the query string's parameters for this page, for links, leaving out the defaults.
     * @return array<string, string>
     */
    public function toQueryParams() : array
    {
        $params = [];

        if ($this->m_sort !== LogSort::NEWEST_FIRST)
        {
            $params['sort'] = $this->m_sort->value;
        }

        if ($this->m_size !== LogPageSize::FIFTY)
        {
            $params['per_page'] = (string)$this->m_size->value;
        }

        if ($this->m_number !== 1)
        {
            $params['page'] = (string)$this->m_number;
        }

        return $params;
    }


    # Accessors
    public function getSort() : LogSort { return $this->m_sort; }
    public function getSize() : LogPageSize { return $this->m_size; }
    public function getNumber() : int { return $this->m_number; }
}
