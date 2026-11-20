<?php

/*
 * An email token that was restricted to particular providers, all of which have since been removed. It stays
 * restricted, so its emails can't be sent through any provider, and wait in the queue until it's given one again (or
 * its provider condition is removed). It's never made from what's entered: only by loading such a token.
 */

declare(strict_types = 1);


readonly class NoRemainingProviders implements ProviderRestrictionInterface
{
    public function allows(ProviderId $id) : bool
    {
        return false;
    }


    public function filter(ProviderDefinitionCollection $providers) : ProviderDefinitionCollection
    {
        return new ProviderDefinitionCollection();
    }
}
