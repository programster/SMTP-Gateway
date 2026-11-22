<?php

/*
 * What to search the logs for: a time range (the start and end are each optional), text to find in the message (not
 * the context), the levels, the categories (see LogCategory, kept in the context), and a name and value in the context
 * (see LogContextMatch). The text is found anywhere in the message, ignoring case, unless its syntax says it's a
 * regular expression (PostgreSQL, PHP or JavaScript, also ignoring case). Which page of what it finds is a LogPage.
 */

declare(strict_types = 1);


readonly class LogFilter
{
    private string $m_from;
    private string $m_to;
    private string $m_search;
    private MessageSearchSyntax $m_syntax;
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
     * @param MessageSearchSyntax $syntax - whether $search is plain text, or which kind of regular expression.
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
        MessageSearchSyntax $syntax,
        LogLevelCollection $levels,
        LogCategoryCollection $categories,
        LogContextMatch $context
    )
    {
        $this->m_from = trim($from);
        $this->m_to = trim($to);
        $this->m_search = $search;
        $this->m_syntax = $syntax;
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
     * @throws InvalidInputException - if a time isn't valid, or the regular expression can't be converted.
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

        if ($this->m_search !== '' && $this->m_syntax->isRegex())
        {
            $conditions[] = '"message" ~* ' . ServiceFactory::getDb()->escapeValue($this->getPostgresPattern());
        }
        elseif ($this->m_search !== '')
        {
            $escapedLike = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $this->m_search);
            $conditions[] = '"message" ILIKE ' . ServiceFactory::getDb()->escapeValue('%' . $escapedLike . '%');
        }

        if (count($this->m_levels) > 0)
        {
            $escaped = [];

            foreach ($this->m_levels as $level)
            {
                $escaped[] = ServiceFactory::getDb()->escapeValue($level->value);
            }

            $conditions[] = '"level" IN (' . implode(', ', $escaped) . ')';
        }

        if (count($this->m_categories) > 0)
        {
            $escaped = [];

            foreach ($this->m_categories as $category)
            {
                $escaped[] = ServiceFactory::getDb()->escapeValue($category->value);
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
        $params['regex'] = $this->m_syntax->value;
        $params['level'] = implode(',', $this->m_levels->column('value'));
        $params['category'] = (count($this->m_categories) > 0) ? $this->m_categories[0]->value : '';
        $params['context_name'] = $this->m_context->getName();
        $params['context_value'] = $this->m_context->getValue();
        return array_filter($params, 'strlen');
    }


    /**
     * Get the regular expression as PostgreSQL runs it.
     * @return string
     * @throws InvalidInputException - if it can't be converted.
     */
    public function getPostgresPattern() : string
    {
        return $this->m_syntax->toPostgresPattern($this->m_search);
    }


    public function isRegex() : bool
    {
        return $this->m_syntax->isRegex();
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
    public function getSearch() : string { return $this->m_search; }
    public function getSyntax() : MessageSearchSyntax { return $this->m_syntax; }
    public function getLevels() : LogLevelCollection { return $this->m_levels; }
    public function getCategories() : LogCategoryCollection { return $this->m_categories; }
    public function getContext() : LogContextMatch { return $this->m_context; }
}
