<?php

/*
 * Loads and replaces the global rate limits: the limits on everything sent through the provider, across all tokens.
 */

declare(strict_types = 1);

use Programster\PgsqlObjects\CacheMode;
use Programster\PgsqlObjects\Transaction;


final class GlobalRateLimitRepository
{
    public static function load() : RateLimitSet
    {
        $limits = [];

        foreach (DB::fetchRows('SELECT "unit", "maximum", "period_seconds" FROM "global_rate_limit"') as $row)
        {
            $unit = RateLimitUnit::from($row['unit']);
            $limits[] = new RateLimit($unit, (int)$row['maximum'], (int)$row['period_seconds']);
        }

        return new RateLimitSet(...$limits);
    }


    /**
     * Replace the global rate limits.
     *
     * @param RateLimitSet $limits - the new limits. An empty set removes every limit.
     *
     * @return void
     */
    public static function replace(RateLimitSet $limits) : void
    {
        // The old limits are deleted and the new ones saved together, in one query, or not at all. The deletes are
        // added first, as a new limit may have the same period as an old one, which must be unique.
        $transaction = new Transaction();

        foreach (GlobalRateLimitTable::getInstance()->loadAll(CacheMode::IGNORE_CACHE) as $existing)
        {
            $transaction->delete($existing);
        }

        foreach ($limits->getLimits() as $limit)
        {
            /* @var $limit RateLimit */
            $record = GlobalRateLimit::createFromArray([
                'unit' => $limit->getUnit()->value,
                'maximum' => $limit->getMax(),
                'period_seconds' => $limit->getPeriodSeconds(),
            ]);

            $transaction->save($record);
        }

        $transaction->commit();
    }
}
