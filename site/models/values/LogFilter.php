<?php

/*
 * What to search the logs for: a time range (the start and end are each optional), text to find in the message (not
 * the context), the levels, the categories (see LogCategory, kept in the context), and a name and value in the context
 * (see LogContextMatch). The text is found anywhere in
 * the message, ignoring case, unless it's marked as a regular expression (PostgreSQL's POSIX syntax, also ignoring
 * case). Which page of what it finds is a LogPage.
 */

declare(strict_types = 1);


readonly class LogFilter
{
    private string $m_from;
    private string $m_to;
    private string $m_search;
    private bool $m_isRegex;
    private LogLevelCollection $m_levels;
    private LogCategoryCollection $m_categories;
    private LogContextMatch $m_context;


    /**
     * @param string $from - the earliest time, as entered (in the configured time zone), or an empty string for
     * no limit.
     *
     * @param string $to - the latest time, as entered, or an empty string for no limit.
     *
     * @param string $search - the text (or regular expression) to find in the message, or an empty string.
     *
     * @param bool $isRegex - whether $search is a regular expression.
     *
     * @param LogLevelCollection $levels - the levels to show, or none for every level.
     *
     * @param LogCategoryCollection $categories - the categories to show, or none for every category.
     *
     * @param LogContextMatch $context - what the context has to have.
     *
     * @throws InvalidInputException - if a time isn't valid, or the start is after the end.
     */
    public function __construct(
        string $from,
        string $to,
        string $search,
        bool $isRegex,
        LogLevelCollection $levels,
        LogCategoryCollection $categories,
        LogContextMatch $context
    )
    {
        $this->m_from = trim($from);
        $this->m_to = trim($to);
        $this->m_search = $search;
        $this->m_isRegex = $isRegex;
        $this->m_levels = $levels;
        $this->m_categories = $categories;
        $this->m_context = $context;

        if (
               $this->m_from !== ''
            && $this->m_to !== ''
            && Time::parseLocalTime($this->m_from) > Time::parseLocalTime($this->m_to)
        )
        {
            throw new InvalidInputException('The "from" time is after the "to" time.');
        }
    }


    /**
     * Get the SQL conditions for this filter, all of which a log entry must meet.
     *
     * @return string[] - each condition, with its values escaped.
     *
     * @throws InvalidInputException - if a time isn't valid.
     */
    public function toSqlConditions() : array
    {
        $conditions = ['TRUE'];

        if ($this->m_from !== '')
        {
            $conditions[] = '"created_at" >= ' . Time::parseLocalTime($this->m_from);
        }

        if ($this->m_to !== '')
        {
            // The "to" time includes the whole of its last minute (or second, if seconds were given).
            $hasSeconds = (substr_count($this->m_to, ':') === 2);
            $conditions[] = '"created_at" < ' . (Time::parseLocalTime($this->m_to) + ($hasSeconds ? 1 : 60));
        }

        if ($this->m_search !== '' && $this->m_isRegex)
        {
            $conditions[] = '"message" ~* ' . DB::escape($this->m_search);
        }
        elseif ($this->m_search !== '')
        {
            $escapedLike = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $this->m_search);
            $conditions[] = '"message" ILIKE ' . DB::escape('%' . $escapedLike . '%');
        }

        if (count($this->m_levels) > 0)
        {
            $escaped = [];

            foreach ($this->m_levels as $level)
            {
                $escaped[] = DB::escape($level->value);
            }

            $conditions[] = '"level" IN (' . implode(', ', $escaped) . ')';
        }

        if (count($this->m_categories) > 0)
        {
            $escaped = [];

            foreach ($this->m_categories as $category)
            {
                $escaped[] = DB::escape($category->value);
            }

            $conditions[] = '"context"->>\'category\' IN (' . implode(', ', $escaped) . ')';
        }

        $conditions[] = $this->m_context->toSqlCondition();

        return $conditions;
    }


    /**
     * Get the query string's parameters for this filter, for links, e.g. to another page, leaving out those not set.
     * @return array<string, string>
     */
    public function toQueryParams() : array
    {
        $params = ['from' => $this->m_from, 'to' => $this->m_to, 'search' => $this->m_search];
        $params['regex'] = $this->m_isRegex ? '1' : '';
        $params['level'] = implode(',', $this->m_levels->column('value'));
        $params['category'] = (count($this->m_categories) > 0) ? $this->m_categories[0]->value : '';
        $params['context_name'] = $this->m_context->getName();
        $params['context_value'] = $this->m_context->getValue();
        return array_filter($params, 'strlen');
    }


    public function isFiltered() : bool
    {
        return (
               $this->m_from !== ''
            || $this->m_to !== ''
            || $this->m_search !== ''
            || count($this->m_levels) > 0
            || count($this->m_categories) > 0
            || $this->m_context->isAny() === false
        );
    }


    # Accessors
    public function getFrom() : string { return $this->m_from; }
    public function getTo() : string { return $this->m_to; }
    public function getSearch() : string { return $this->m_search; }
    public function isRegex() : bool { return $this->m_isRegex; }
    public function getLevels() : LogLevelCollection { return $this->m_levels; }
    public function getCategories() : LogCategoryCollection { return $this->m_categories; }
    public function getContext() : LogContextMatch { return $this->m_context; }
}
