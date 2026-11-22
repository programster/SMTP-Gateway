<?php

/*
 * When a provider refuses an email as over its own rate limit (see RateLimitReply), though the rate limits set for it
 * here allowed the email, those limits may be higher than the provider's own. This logs a warning saying so, with how
 * much was sent through the provider in each recent window, so the right limit can be set: whichever of the provider's
 * limits was reached is at or below one of those counts.
 *
 * It can't be certain: if other systems send through the same account with the provider, they use up its limit too,
 * without this service knowing, and the limits here may be right. The warning says so.
 */

declare(strict_types = 1);


final class ProviderRateLimitWarning
{
    // The windows counted, as rate limits are usually set: per minute, hour, day and month.
    private const array WINDOWS = ['minute' => 60, 'hour' => 3600, 'day' => 86400, '30 days' => 2592000];


    /**
     * Log the warning for a provider that has just refused an email as over its rate limit. If the provider has been
     * removed from the settings meanwhile, there are no limits here to check, so it says only what happened.
     *
     * @param string $providerName - the provider.
     *
     * @param string $reply - what it replied.
     *
     * @return void
     */
    public static function log(string $providerName, string $reply) : void
    {
        try
        {
            $limits = ProvidersFormService::loadProvider($providerName)->getRateLimits();
        }
        catch (NotFoundException | ConfigurationException)
        {
            $limits = new RateLimitSet();
        }

        $usage = self::loadUsage($providerName);
        $context = ['provider' => $providerName, 'reply' => $reply];

        foreach ($usage as $window)
        {
            /* @var $window ProviderUsageWindow */
            $context['sent_last_' . str_replace(' ', '_', $window->getLabel())] = $window->getEmails();
        }

        $logger = ServiceFactory::getLogger(LogCategory::EMAIL);
        $logger->warning(self::describe($providerName, $limits, $usage), $context);
    }


    /**
     * Describe what happened, and what to do about it: which of the provider's limits was reached isn't known, so the
     * suggestion is the most each window's limit could be, for each window the limits here don't already cap.
     *
     * @param string $providerName - the provider.
     *
     * @param RateLimitSet $limits - the rate limits set for it here.
     *
     * @param ProviderUsageWindowCollection $usage - how much was sent through it in each recent window.
     *
     * @return string
     */
    public static function describe(
        string $providerName,
        RateLimitSet $limits,
        ProviderUsageWindowCollection $usage
    ) : string
    {
        $suggestions = [];

        foreach ($usage as $window)
        {
            /* @var $window ProviderUsageWindow */
            if ($window->getEmails() > 0 && !self::isCapped($limits, $window))
            {
                $suggestions[] = number_format($window->getEmails()) . " emails per {$window->getLabel()}";
            }
        }

        $limitsHere = $limits->isEmpty() ? 'none of its own' : $limits->describe();
        $last = array_pop($suggestions);
        $listed = (count($suggestions) > 0) ? implode(', ', $suggestions) . ", or {$last}" : (string)$last;

        $suggestion = ($last === null)
            ? ''
            : " Depending on which of its limits it is (per minute, hour, day or month), that's at most {$listed}:"
                . ' set the matching limit for it under Providers.';

        return
            "Provider {$providerName} refused an email as over its own rate limit, though its rate limits here"
            . " ({$limitsHere}) allowed it, so they may be higher than the provider's.{$suggestion} That's"
            . ' assuming nothing else sends through the same account: if other systems do, they use up its limit'
            . ' too, and the limits here may be right. Meanwhile, the email goes through another provider, or waits.';
    }


    /**
     * Load how much was sent through a provider (accepted by it) in each window.
     * @param string $providerName - the provider.
     * @return ProviderUsageWindowCollection
     */
    private static function loadUsage(string $providerName) : ProviderUsageWindowCollection
    {
        $columns = [];

        foreach (self::WINDOWS as $label => $seconds)
        {
            $filter = "FILTER (WHERE \"dispatched_at\" > now() - make_interval(secs => {$seconds}))";
            $alias = str_replace(' ', '_', $label);
            $columns[] = "count(*) {$filter} AS \"e_{$alias}\"";
            $columns[] = "coalesce(sum(\"recipient_count\") {$filter}, 0) AS \"r_{$alias}\"";
        }

        $longest = max(self::WINDOWS);

        $query =
            'SELECT ' . implode(', ', $columns) . ' FROM "email_attempt"'
            . ' WHERE "provider" = ' . ServiceFactory::getDb()->escapeValue($providerName) . " AND \"outcome\" = 'sent'"
            . " AND \"dispatched_at\" > now() - make_interval(secs => {$longest})";

        $row = pg_fetch_all(ServiceFactory::getDb()->query($query))[0];
        $windows = [];

        foreach (self::WINDOWS as $label => $seconds)
        {
            $alias = str_replace(' ', '_', $label);
            $windows[] = new ProviderUsageWindow($label, $seconds, (int)$row["e_{$alias}"], (int)$row["r_{$alias}"]);
        }

        return new ProviderUsageWindowCollection(...$windows);
    }


    // Whether a limit here, over no longer than the window, already allows no more emails than were sent in it.
    private static function isCapped(RateLimitSet $limits, ProviderUsageWindow $window) : bool
    {
        $isCapped = false;

        foreach ($limits->getLimits() as $limit)
        {
            /* @var $limit RateLimit */
            $isCapped = (
                   $isCapped
                || (
                       $limit->getUnit() === RateLimitUnit::EMAILS
                    && $limit->getPeriodSeconds() === $window->getPeriodSeconds()
                    && $limit->getMax() <= $window->getEmails()
                )
            );
        }

        return $isCapped;
    }
}
