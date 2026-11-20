<?php

/*
 * The email tokens (not revoked) restricted to particular providers, with the providers each is linked to, e.g. to say
 * on a provider's page which tokens use it, and which would be left with no enabled provider without it.
 */

declare(strict_types = 1);

use Programster\Collections\AbstractReadonlyCollection;


/**
 * @extends AbstractReadonlyCollection<ProviderTokenLink>
 */
final readonly class ProviderTokenLinkCollection extends AbstractReadonlyCollection
{
    public function __construct(ProviderTokenLink ...$links)
    {
        parent::__construct(...$links);
    }


    /**
     * Get the ones linked to a provider.
     * @param ProviderId $providerId - the provider's id.
     * @return ProviderTokenLinkCollection
     */
    public function filterLinkedTo(ProviderId $providerId) : ProviderTokenLinkCollection
    {
        $linked = [];

        foreach ($this as $link)
        {
            /* @var $link ProviderTokenLink */
            if ($link->getProviderIds()->includes($providerId))
            {
                $linked[] = $link;
            }
        }

        return new ProviderTokenLinkCollection(...$linked);
    }


    /**
     * Get the ones linked to a provider that would be left with no enabled provider without it (e.g. if it were
     * removed or disabled), so their emails would wait in the queue.
     *
     * @param ProviderId $providerId - the provider's id.
     *
     * @param ProvidersConfig $config - every provider.
     *
     * @return ProviderTokenLinkCollection
     */
    public function filterStrandedWithout(ProviderId $providerId, ProvidersConfig $config) : ProviderTokenLinkCollection
    {
        $stranded = [];

        foreach ($this->filterLinkedTo($providerId) as $link)
        {
            /* @var $link ProviderTokenLink */
            if ($link->wouldHaveNoEnabledProvider($providerId, $config))
            {
                $stranded[] = $link;
            }
        }

        return new ProviderTokenLinkCollection(...$stranded);
    }


    /**
     * Get the tokens' names, quoted and separated by commas, for a message, e.g. "Billing", "Reports".
     * @return string
     */
    public function describeNames() : string
    {
        $names = [];

        foreach ($this as $link)
        {
            /* @var $link ProviderTokenLink */
            $names[] = "\"{$link->getEmailToken()->getName()}\"";
        }

        return implode(', ', $names);
    }
}
