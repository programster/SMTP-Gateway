<?php

/*
 * The providers an email token's emails may be sent through, by their ids. The mode then applies to those of them
 * that are enabled, as if they were the only providers. It can't be empty, as that would read as "none" (see
 * NoRemainingProviders for a token whose providers have all been removed).
 */

declare(strict_types = 1);


readonly class ProviderAllowList implements ProviderRestrictionInterface
{
    private ProviderIdCollection $m_ids;


    /**
     * @param ProviderId ...$ids - the providers' ids. At least one is required. Duplicates are dropped.
     * @throws InvalidInputException - if none were given.
     */
    public function __construct(ProviderId ...$ids)
    {
        if (count($ids) === 0)
        {
            $msg = 'At least one provider is required. To let it use any provider, leave the provider condition out.';
            throw new InvalidInputException($msg);
        }

        $unique = [];

        foreach ($ids as $id)
        {
            $unique[$id->getValue()] = $id;
        }

        $this->m_ids = new ProviderIdCollection(...array_values($unique));
    }


    public function allows(ProviderId $id) : bool
    {
        return $this->m_ids->includes($id);
    }


    public function filter(ProviderDefinitionCollection $providers) : ProviderDefinitionCollection
    {
        $allowed = [];

        foreach ($providers as $provider)
        {
            /* @var $provider ProviderDefinition */
            if ($this->allows($provider->getId()))
            {
                $allowed[] = $provider;
            }
        }

        return new ProviderDefinitionCollection(...$allowed);
    }


    # Accessors
    public function getIds() : ProviderIdCollection { return $this->m_ids; }
}
