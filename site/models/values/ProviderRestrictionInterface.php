<?php

/*
 * Which providers an email token's emails may be sent through: any of them (AnyProvider), a list of them, by id
 * (ProviderAllowList), or none, as every provider it was restricted to has since been removed (NoRemainingProviders).
 * Each is a type of its own, rather than an empty list meaning one or the other, so a token that loses its last
 * provider can never be mistaken for one that may use them all.
 */

declare(strict_types = 1);


interface ProviderRestrictionInterface
{
    /**
     * Check whether a provider may be sent through.
     * @param ProviderId $id - the provider's id.
     * @return bool
     */
    public function allows(ProviderId $id) : bool;


    /**
     * Keep only the providers that may be sent through, in the order they're given in (which is their order in
     * settings.yaml, which the overflow and fallback modes use).
     *
     * @param ProviderDefinitionCollection $providers - the providers, e.g. the enabled ones.
     *
     * @return ProviderDefinitionCollection
     */
    public function filter(ProviderDefinitionCollection $providers) : ProviderDefinitionCollection;
}
