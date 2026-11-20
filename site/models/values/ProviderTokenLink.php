<?php

/*
 * An email token (not revoked) that's restricted to particular providers, with the ids of the providers it's linked to.
 */

declare(strict_types = 1);


final readonly class ProviderTokenLink
{
    private EmailToken $m_emailToken;
    private ProviderIdCollection $m_providerIds;


    /**
     * @param EmailToken $emailToken - the token.
     * @param ProviderIdCollection $providerIds - the providers it's restricted to.
     */
    public function __construct(EmailToken $emailToken, ProviderIdCollection $providerIds)
    {
        $this->m_emailToken = $emailToken;
        $this->m_providerIds = $providerIds;
    }


    /**
     * Check whether it would be left with no enabled provider without one of them (e.g. if it were removed, or
     * disabled), so its emails would wait in the queue. Only asked of a token linked to that provider.
     *
     * @param ProviderId $without - the provider it would be without.
     *
     * @param ProvidersConfig $config - every provider.
     *
     * @return bool
     */
    public function wouldHaveNoEnabledProvider(ProviderId $without, ProvidersConfig $config) : bool
    {
        $hasAnother = false;

        foreach ($config->getEnabledProviders() as $provider)
        {
            /* @var $provider ProviderDefinition */
            $isOther = !$provider->getId()->equals($without);
            $hasAnother = ($hasAnother || ($isOther && $this->m_providerIds->includes($provider->getId())));
        }

        return !$hasAnother;
    }


    # Accessors
    public function getEmailToken() : EmailToken { return $this->m_emailToken; }
    public function getProviderIds() : ProviderIdCollection { return $this->m_providerIds; }
}
