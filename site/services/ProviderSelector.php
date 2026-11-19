<?php

/*
 * Chooses which provider an email is sent through, following the mode in settings.yaml:
 *  - round_robin: the next provider after the one used most recently, skipping any without room under their limits,
 *  - overflow: the first provider with room under its limits,
 *  - fallback: the first provider, whatever its limits (the email waits for it if it has no room).
 * In every mode, providers that have already been tried for the email in this round, and providers that couldn't be
 * connected to recently (see ProviderStatusRepository), are skipped.
 */

declare(strict_types = 1);


final class ProviderSelector
{
    /**
     * Choose the provider to send an email through now. Must be called while holding the dispatch lock.
     *
     * @param ProvidersConfig $config - the providers.
     *
     * @param int $recipientCount - the email's number of recipients.
     *
     * @param string[] $triedProviders - the names of the providers already tried for it in this round.
     *
     * @return string - the provider's name, or an empty string if none can take it now.
     */
    public static function choose(ProvidersConfig $config, int $recipientCount, array $triedProviders) : string
    {
        $candidates = self::getCandidates($config, $triedProviders);
        $chosen = '';

        if ($config->getMode() === ProviderMode::FALLBACK)
        {
            // Only the first candidate can be used. If it has no room, the email waits for it, rather than skipping it.
            if (count($candidates) > 0 && self::hasRoom($candidates->first(), $recipientCount))
            {
                $chosen = $candidates->first()->getName();
            }
        }
        else
        {
            foreach ($candidates as $provider)
            {
                /* @var $provider ProviderDefinition */
                if ($chosen === '' && self::hasRoom($provider, $recipientCount))
                {
                    $chosen = $provider->getName();
                }
            }
        }

        return $chosen;
    }


    /**
     * Work out how long until a provider could take an email, for estimating when it will be sent: the soonest any
     * of them could (or, in fallback mode, the first one could).
     *
     * @param ProvidersConfig $config - the providers.
     *
     * @param int $recipientCount - the email's number of recipients.
     *
     * @param QueuedDemand $ahead - what's queued ahead of the email. As which provider each of those will use isn't
     * known yet, it's treated as all going to whichever provider is being considered.
     *
     * @return float - seconds from now, or INF if no provider is available.
     */
    public static function getSecondsUntilAProviderHasRoom(
        ProvidersConfig $config,
        int $recipientCount,
        QueuedDemand $ahead
    ) : float
    {
        $wait = INF;
        $isFirst = true;

        foreach (self::getCandidates($config, []) as $provider)
        {
            /* @var $provider ProviderDefinition */
            $scope = RateLimitScope::forProvider($provider->getName());
            $limits = $provider->getRateLimits();
            $providerWait = RateLimiter::getSecondsUntilRoom($limits, $scope, $recipientCount, $ahead);

            if ($config->getMode() !== ProviderMode::FALLBACK || $isFirst)
            {
                $wait = min($wait, $providerWait);
            }

            $isFirst = false;
        }

        return $wait;
    }


    /**
     * Get the providers that could be used, in the order to try them.
     *
     * @param ProvidersConfig $config - the providers.
     *
     * @param string[] $triedProviders - the providers to leave out, as they've already been tried.
     *
     * @return ProviderDefinitionCollection
     */
    private static function getCandidates(ProvidersConfig $config, array $triedProviders) : ProviderDefinitionCollection
    {
        $unavailable = ProviderStatusRepository::loadUnavailableNames();
        $enabled = $config->getEnabledProviders();
        $count = count($enabled);
        $start = ($config->getMode() === ProviderMode::ROUND_ROBIN) ? self::getRoundRobinStart($enabled) : 0;
        $candidates = [];

        for ($i = 0; $i < $count; $i++)
        {
            /* @var $provider ProviderDefinition */
            $provider = $enabled[($start + $i) % $count];
            $name = $provider->getName();

            if (in_array($name, $triedProviders, true) === false && in_array($name, $unavailable, true) === false)
            {
                $candidates[] = $provider;
            }
        }

        return new ProviderDefinitionCollection(...$candidates);
    }


    /**
     * Get the position to start from in round robin mode: the one after the provider used most recently, so they
     * take turns.
     *
     * @param ProviderDefinitionCollection $providers - the enabled providers, in order.
     *
     * @return int - the position, from 0.
     */
    private static function getRoundRobinStart(ProviderDefinitionCollection $providers) : int
    {
        $names = [];

        foreach ($providers as $provider)
        {
            /* @var $provider ProviderDefinition */
            $names[] = $provider->getName();
        }

        $escapedNames = implode(', ', array_map([DB::class, 'escape'], $names));

        $query =
            "SELECT \"provider\" FROM \"email_message\" WHERE \"provider\" IN ({$escapedNames})"
            . ' AND "dispatched_at" IS NOT NULL ORDER BY "dispatched_at" DESC LIMIT 1';

        $rows = DB::fetchRows($query);
        $position = (count($rows) > 0) ? array_search($rows[0]['provider'], $names, true) : false;
        return ($position === false) ? 0 : ($position + 1) % count($names);
    }


    private static function hasRoom(ProviderDefinition $provider, int $recipientCount) : bool
    {
        $scope = RateLimitScope::forProvider($provider->getName());
        return RateLimiter::allows($provider->getRateLimits(), $scope, $recipientCount);
    }
}
