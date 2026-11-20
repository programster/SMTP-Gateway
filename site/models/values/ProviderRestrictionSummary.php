<?php

/*
 * What an email token's provider restriction means now, for its page: which providers its emails can go through (by
 * name, as the restriction only holds their ids), and a warning if they can't go through any, so wait in the queue.
 */

declare(strict_types = 1);


final readonly class ProviderRestrictionSummary
{
    private string $m_description;
    private string $m_warning;


    /**
     * @param ProviderRestrictionInterface $restriction - the token's provider restriction.
     *
     * @param ProviderDefinitionCollection $configured - the providers in settings.yaml (enabled or not), in order, or
     * none if there aren't any yet.
     */
    public function __construct(ProviderRestrictionInterface $restriction, ProviderDefinitionCollection $configured)
    {
        $lines = [];
        $enabledCount = 0;

        foreach ($restriction->filter($configured) as $provider)
        {
            /* @var $provider ProviderDefinition */
            $lines[] = $provider->getName() . ($provider->isEnabled() ? '' : ' (disabled)');
            $enabledCount += $provider->isEnabled() ? 1 : 0;
        }

        $this->m_description = match (true) {
            $restriction instanceof AnyProvider => 'Any provider',
            count($lines) === 0 => 'None',
            default => implode("\n", $lines),
        };

        $noneLeft =
            'Every provider it was restricted to has been removed, so its emails wait in the queue until it\'s given'
            . ' one (edit it to choose some, or to let it use any).';

        $noneEnabled = 'None of its providers is enabled, so its emails wait in the queue until one of them is.';

        $this->m_warning = match (true) {
            $restriction instanceof AnyProvider => '',
            count($lines) === 0 => $noneLeft,
            $enabledCount === 0 => $noneEnabled,
            default => '',
        };
    }


    # Accessors
    public function getDescription() : string { return $this->m_description; }
    public function getWarning() : string { return $this->m_warning; }
}
