<?php

/*
 * The order the logs are listed in: by time, or by level (the most severe first, or the least), each newest first
 * within a level.
 */

declare(strict_types = 1);


enum LogSort : string
{
    case NEWEST_FIRST = 'newest';
    case OLDEST_FIRST = 'oldest';
    case MOST_SEVERE_FIRST = 'severe';
    case LEAST_SEVERE_FIRST = 'mild';


    /**
     * Get the SQL ORDER BY for it, with the ID last, so entries logged at the same moment keep one order.
     * @return string
     */
    public function getSqlOrder() : string
    {
        return match ($this) {
            LogSort::NEWEST_FIRST => '"created_at" DESC, "id" DESC',
            LogSort::OLDEST_FIRST => '"created_at" ASC, "id" ASC',
            LogSort::MOST_SEVERE_FIRST => '"level" DESC, "created_at" DESC, "id" DESC',
            LogSort::LEAST_SEVERE_FIRST => '"level" ASC, "created_at" DESC, "id" DESC',
        };
    }


    /**
     * Get the order a column's heading switches to when it's clicked: this column's other direction, if it's
     * already sorted by it, or its usual direction (newest, or most severe, first), if not.
     *
     * @param bool $isLevel - whether it's the level's column, rather than the time's.
     *
     * @return LogSort
     */
    public function getToggleFor(bool $isLevel) : LogSort
    {
        return match (true) {
            $isLevel && $this === LogSort::MOST_SEVERE_FIRST => LogSort::LEAST_SEVERE_FIRST,
            $isLevel => LogSort::MOST_SEVERE_FIRST,
            $this === LogSort::NEWEST_FIRST => LogSort::OLDEST_FIRST,
            default => LogSort::NEWEST_FIRST,
        };
    }


    /**
     * Get the arrow showing a column is sorted by this, and which way: ↓ for newest or most severe first, or none.
     *
     * @param bool $isLevel - whether it's the level's column, rather than the time's.
     *
     * @return string
     */
    public function getArrowFor(bool $isLevel) : string
    {
        return match (true) {
            $isLevel && $this === LogSort::MOST_SEVERE_FIRST, !$isLevel && $this === LogSort::NEWEST_FIRST => '↓',
            $isLevel && $this === LogSort::LEAST_SEVERE_FIRST, !$isLevel && $this === LogSort::OLDEST_FIRST => '↑',
            default => '',
        };
    }
}
