<?php

/*
 * No restriction on which providers an email token's emails go through: every enabled provider may be used, as the
 * mode decides. It's what an email token has when it was given no provider condition, and what system emails use.
 */

declare(strict_types = 1);


readonly class AnyProvider implements ProviderRestrictionInterface
{
    public function allows(ProviderId $id) : bool
    {
        return true;
    }


    public function filter(ProviderDefinitionCollection $providers) : ProviderDefinitionCollection
    {
        return $providers;
    }
}
