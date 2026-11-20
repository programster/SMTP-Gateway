<?php

/*
 * The providers section of settings.yaml: the providers, and the mode for sharing emails between them. It can't be
 * created invalid: ids and names must be unique, and at least one provider must be enabled (with none, nothing could
 * ever be sent).
 */

declare(strict_types = 1);


readonly class ProvidersConfig
{
    private ProviderMode $m_mode;
    private ProviderDefinitionCollection $m_providers;


    /**
     * @param ProviderMode $mode - how emails are shared between the providers.
     *
     * @param ProviderDefinition ...$providers - the providers, in order (which "overflow" and "fallback" use).
     *
     * @throws InvalidInputException - if two have the same id or name, or none is enabled.
     */
    public function __construct(ProviderMode $mode, ProviderDefinition ...$providers)
    {
        $names = [];
        $namesById = [];
        $enabledCount = 0;

        foreach ($providers as $provider)
        {
            $id = $provider->getId()->getValue();

            if (isset($names[$provider->getName()]))
            {
                $msg = "There are two providers called \"{$provider->getName()}\". Each needs its own name.";
                throw new InvalidInputException($msg);
            }

            if (isset($namesById[$id]))
            {
                $msg =
                    "Providers \"{$namesById[$id]}\" and \"{$provider->getName()}\" have the same id, {$id}. Each needs"
                    . ' its own: leave one\'s "id" out, and a new one will be made for it.';

                throw new InvalidInputException($msg);
            }

            $names[$provider->getName()] = true;
            $namesById[$id] = $provider->getName();
            $enabledCount += $provider->isEnabled() ? 1 : 0;
        }

        if ($enabledCount === 0)
        {
            throw new InvalidInputException('At least one provider must be enabled, or no email could ever be sent.');
        }

        $this->m_mode = $mode;
        $this->m_providers = new ProviderDefinitionCollection(...$providers);
    }


    /**
     * Get the enabled providers, in order.
     *
     * @return ProviderDefinitionCollection
     */
    public function getEnabledProviders() : ProviderDefinitionCollection
    {
        $enabled = [];

        foreach ($this->m_providers as $provider)
        {
            /* @var $provider ProviderDefinition */
            if ($provider->isEnabled())
            {
                $enabled[] = $provider;
            }
        }

        return new ProviderDefinitionCollection(...$enabled);
    }


    /**
     * Get a provider by its name.
     *
     * @param string $name - the provider's name.
     *
     * @return ProviderDefinition
     *
     * @throws NotFoundException - if there's no provider with that name.
     */
    public function getProvider(string $name) : ProviderDefinition
    {
        $found = null;

        foreach ($this->m_providers as $provider)
        {
            /* @var $provider ProviderDefinition */
            if ($found === null && $provider->getName() === $name)
            {
                $found = $provider;
            }
        }

        if ($found === null)
        {
            throw new NotFoundException("There's no provider called \"{$name}\" in settings.yaml.");
        }

        return $found;
    }


    /**
     * Find a provider by its id.
     *
     * @param ProviderId $id - the provider's id.
     *
     * @return ProviderDefinition
     *
     * @throws NotFoundException - if there's no provider with that id.
     */
    public function getProviderById(ProviderId $id) : ProviderDefinition
    {
        $found = null;

        foreach ($this->m_providers as $provider)
        {
            /* @var $provider ProviderDefinition */
            if ($found === null && $provider->getId()->equals($id))
            {
                $found = $provider;
            }
        }

        if ($found === null)
        {
            throw new NotFoundException("There's no provider with the id {$id} in settings.yaml.");
        }

        return $found;
    }


    /**
     * Check whether there's a provider with an id (enabled or not).
     * @param ProviderId $id - the id.
     * @return bool
     */
    public function hasProviderWithId(ProviderId $id) : bool
    {
        return $this->getIds()->includes($id);
    }


    /**
     * Get every provider's id, in order.
     * @return ProviderIdCollection
     */
    public function getIds() : ProviderIdCollection
    {
        $ids = [];

        foreach ($this->m_providers as $provider)
        {
            /* @var $provider ProviderDefinition */
            $ids[] = $provider->getId();
        }

        return new ProviderIdCollection(...$ids);
    }


    # Accessors
    public function getMode() : ProviderMode { return $this->m_mode; }
    public function getProviders() : ProviderDefinitionCollection { return clone $this->m_providers; }
}
