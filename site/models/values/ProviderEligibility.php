<?php

/*
 * Which providers one email can be sent through: those its email token may use (see ProviderRestrictionInterface) that
 * also take every address it's sent as (its envelope sender, and its From and Sender addresses: see
 * ProviderDefinition::takesAll()). The mode then applies to those of them that are enabled, as if they were the only
 * providers.
 */

declare(strict_types = 1);


final readonly class ProviderEligibility
{
    private ProviderRestrictionInterface $m_allowed;
    private EmailAddressCollection $m_senders;


    /**
     * @param ProviderRestrictionInterface $allowed - the providers the email's token may use (AnyProvider for one of
     * the service's own emails).
     *
     * @param EmailAddressCollection $senders - every address the email is sent as (see RawEmail::getSenderAddresses()).
     */
    public function __construct(ProviderRestrictionInterface $allowed, EmailAddressCollection $senders)
    {
        $this->m_allowed = $allowed;
        $this->m_senders = $senders;
    }


    /**
     * Keep only the providers the email can be sent through, in the order they're given in.
     * @param ProviderDefinitionCollection $providers - the providers, e.g. the enabled ones.
     * @return ProviderDefinitionCollection
     */
    public function filter(ProviderDefinitionCollection $providers) : ProviderDefinitionCollection
    {
        $eligible = [];

        foreach ($this->m_allowed->filter($providers) as $provider)
        {
            /* @var $provider ProviderDefinition */
            if ($provider->takesAll($this->m_senders))
            {
                $eligible[] = $provider;
            }
        }

        return new ProviderDefinitionCollection(...$eligible);
    }


    /**
     * Check whether no provider would ever take the email: its token may use some of the providers (enabled or not),
     * but none of those takes all its senders. A token with no providers left isn't this, as it's given one again
     * by being changed, rather than by changing what's sent.
     *
     * @param ProvidersConfig $config - every provider.
     *
     * @return bool
     */
    public function isRefusedByEveryProvider(ProvidersConfig $config) : bool
    {
        $usable = $this->m_allowed->filter($config->getProviders());
        return (count($usable) > 0 && count($this->filter($usable)) === 0);
    }


    /**
     * Describe the addresses that none of the token's providers take, for the message refusing (or failing) it.
     * @param ProvidersConfig $config - every provider.
     * @return string - e.g. "stuart@stuart.page".
     */
    public function describeSendersNotTaken(ProvidersConfig $config) : string
    {
        $notTaken = [];
        $usable = $this->m_allowed->filter($config->getProviders());

        foreach ($this->m_senders as $sender)
        {
            /* @var $sender EmailAddress */
            $isTaken = false;

            foreach ($usable as $provider)
            {
                /* @var $provider ProviderDefinition */
                $isTaken = ($isTaken || $provider->takesAll(new EmailAddressCollection($sender)));
            }

            if (!$isTaken)
            {
                $notTaken[] = (string)$sender;
            }
        }

        // When each is taken by some provider, but no one provider takes them all, they're named together.
        return (count($notTaken) > 0) ? implode(', ', $notTaken) : implode(', ', $this->getSenderStrings());
    }


    /**
     * Get the senders as strings, e.g. for storing with the email.
     * @return string[]
     */
    public function getSenderStrings() : array
    {
        $strings = [];

        foreach ($this->m_senders as $sender)
        {
            /* @var $sender EmailAddress */
            $strings[] = (string)$sender;
        }

        return $strings;
    }


    # Accessors
    public function getAllowed() : ProviderRestrictionInterface { return $this->m_allowed; }
    public function getSenders() : EmailAddressCollection { return $this->m_senders; }
}
