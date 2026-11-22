<?php

/*
 * Chooses which provider an email is sent through, following the mode in settings.yaml:
 *  - round_robin: the next provider after the one used most recently, skipping any without room under their limits,
 *  - overflow: the first provider with room under its limits,
 *  - fallback: the first provider, whatever its limits (the email waits for it if it has no room).
 * In every mode, providers that have already been tried for the email in this round, and providers that couldn't be
 * connected to recently (see ProviderStatusRepository), are skipped.
 *
 * An email whose token is restricted to particular providers only ever uses those of them that are enabled, and the
 * mode applies to them as if they were the only providers, in their order in settings.yaml (so with one, that one is
 * always used). With none of them enabled, no provider can take it, so it waits in the queue. Likewise, a provider
 * restricted to particular senders is only used for emails it takes (see ProviderEligibility).
 */

declare(strict_types = 1);


final class ProviderSelector
{
    /**
     * Choose the provider to send an email through now. Must be called while holding the dispatch lock.
     *
     * @param ProvidersConfig $config - the providers.
     *
     * @param ProviderEligibility $eligibility - which providers the email can be sent through: those its token may
     * use, that take its senders.
     *
     * @param int $recipientCount - the email's number of recipients.
     *
     * @param string[] $triedProviders - the names of the providers already tried for it in this round.
     *
     * @param string[] $reservedProviders - the names of the providers that higher priority emails are waiting for
     * room under, which this one mustn't take, so it's treated as having none.
     *
     * @return string - the provider's name, or an empty string if none can take it now.
     */
    public static function choose(
        ProvidersConfig $config,
        ProviderEligibility $eligibility,
        int $recipientCount,
        array $triedProviders,
        array $reservedProviders
    ) : string
    {
        $chosen = '';

        foreach (self::getUsableNow($config, $eligibility, $triedProviders) as $provider)
        {
            /* @var $provider ProviderDefinition */
            $isFree = !in_array($provider->getName(), $reservedProviders, true);

            if ($chosen === '' && $isFree && self::hasRoom($provider, $recipientCount))
            {
                $chosen = $provider->getName();
            }
        }

        return $chosen;
    }


    /**
     * Get the providers an email could be sent through now, if they had room: the ones choose() picks from, in the
     * order it tries them. In fallback mode, that's only the first, as the email waits for it rather than skipping it.
     * An email waiting for room keeps these for itself (see MessageDispatcher), so no lower priority email takes it.
     *
     * @param ProvidersConfig $config - the providers.
     *
     * @param ProviderEligibility $eligibility - which providers the email can be sent through.
     *
     * @param string[] $triedProviders - the names of the providers already tried for it in this round.
     *
     * @return ProviderDefinitionCollection
     */
    public static function getUsableNow(
        ProvidersConfig $config,
        ProviderEligibility $eligibility,
        array $triedProviders
    ) : ProviderDefinitionCollection
    {
        $candidates = self::getCandidates($config, $eligibility, $triedProviders);
        $isFallback = ($config->getMode() === ProviderMode::FALLBACK);

        return ($isFallback && count($candidates) > 1)
            ? new ProviderDefinitionCollection($candidates->first())
            : $candidates;
    }


    /**
     * Work out how long until a provider could take an email, for estimating when it will be sent: the soonest any
     * of them could (or, in fallback mode, the first one could).
     *
     * @param ProvidersConfig $config - the providers.
     *
     * @param ProviderEligibility $eligibility - which providers the email can be sent through.
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
        ProviderEligibility $eligibility,
        int $recipientCount,
        QueuedDemand $ahead
    ) : float
    {
        $wait = INF;

        foreach (self::getUsableNow($config, $eligibility, []) as $provider)
        {
            /* @var $provider ProviderDefinition */
            $scope = RateLimitScope::forProvider($provider->getName());
            $limits = $provider->getRateLimits();
            $wait = min($wait, RateLimiter::getSecondsUntilRoom($limits, $scope, $recipientCount, $ahead));
        }

        return $wait;
    }


    /**
     * Get the providers that could be used, in the order to try them.
     *
     * @param ProvidersConfig $config - the providers.
     *
     * @param ProviderEligibility $eligibility - which providers the email can be sent through. The others are left
     * out before round robin's turn is worked out, so they take turns as if they were the only providers.
     *
     * @param string[] $triedProviders - the providers to leave out, as they've already been tried.
     *
     * @return ProviderDefinitionCollection
     */
    private static function getCandidates(
        ProvidersConfig $config,
        ProviderEligibility $eligibility,
        array $triedProviders
    ) : ProviderDefinitionCollection
    {
        $unavailable = ProviderStatusRepository::loadUnavailableNames();
        $enabled = $eligibility->filter($config->getEnabledProviders());
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
     * @param ProviderDefinitionCollection $providers - the providers that may be used, in order. For an email whose
     * token is restricted, that's just its own, so the one used most recently is the most recent of those.
     *
     * @return int - the position, from 0 (which it is when there are none).
     */
    private static function getRoundRobinStart(ProviderDefinitionCollection $providers) : int
    {
        $names = [];

        foreach ($providers as $provider)
        {
            /* @var $provider ProviderDefinition */
            $names[] = $provider->getName();
        }

        $rows = [];

        if (count($names) > 0)
        {
            $escapedNames = implode(', ', ServiceFactory::getDb()->escapeValues($names));

            $query =
                "SELECT \"provider\" FROM \"email_message\" WHERE \"provider\" IN ({$escapedNames})"
                . ' AND "dispatched_at" IS NOT NULL ORDER BY "dispatched_at" DESC LIMIT 1';

            $rows = pg_fetch_all(ServiceFactory::getDb()->query($query));
        }

        $position = (count($rows) > 0) ? array_search($rows[0]['provider'], $names, true) : false;
        return ($position === false) ? 0 : ($position + 1) % count($names);
    }


    private static function hasRoom(ProviderDefinition $provider, int $recipientCount) : bool
    {
        $scope = RateLimitScope::forProvider($provider->getName());
        return RateLimiter::allows($provider->getRateLimits(), $scope, $recipientCount);
    }
}
