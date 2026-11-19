<?php

/*
 * What removing a provider would do, for the dialog that asks before it's removed (ViewRemoveProviderDialog): whether
 * it can be removed at all, and what to warn about. The only enabled provider can't be removed, as no email could be
 * sent without one, so the dialog says so before it's asked, rather than the removal failing after.
 */

declare(strict_types = 1);


final readonly class ProviderRemovalNotice
{
    private bool $m_isRefused;
    private string $m_message;


    /**
     * @param bool $isRefused - whether it can't be removed.
     *
     * @param string $message - why it can't be, or what removing it would do.
     */
    private function __construct(bool $isRefused, string $message)
    {
        $this->m_isRefused = $isRefused;
        $this->m_message = $message;
    }


    /**
     * Work out what removing a provider would do, given every provider.
     *
     * @param ProviderDefinition $provider - the provider.
     *
     * @param ProvidersConfig $config - every provider, including it.
     *
     * @return ProviderRemovalNotice
     */
    public static function createForProvider(
        ProviderDefinition $provider,
        ProvidersConfig $config
    ) : ProviderRemovalNotice
    {
        $others = [];

        foreach ($config->getEnabledProviders() as $enabled)
        {
            /* @var $enabled ProviderDefinition */
            if ($enabled->getName() !== $provider->getName())
            {
                $others[] = "\"{$enabled->getName()}\"";
            }
        }

        $webhook = $provider->getType()->hasWebhook()
            ? ' If its webhook is set up, it stops accepting events, so what happens to the emails already sent'
                . ' through it (deliveries, bounces and so on) won\'t be recorded.'
            : '';

        if ($provider->isEnabled() && count($others) === 0)
        {
            $refusal =
                'It\'s the only enabled provider, so it can\'t be removed: without one, this service couldn\'t send any'
                . ' email at all, not even password reset links. Add or enable another provider first.';

            $notice = new ProviderRemovalNotice(true, $refusal);
        }
        elseif ($provider->isEnabled())
        {
            $providers = (count($others) === 1) ? 'provider' : 'providers';
            $instead = "Emails will be sent through the other enabled {$providers} instead: " . implode(', ', $others);
            $notice = new ProviderRemovalNotice(false, "{$instead}.{$webhook}");
        }
        else
        {
            $disabled = 'It\'s disabled, so no emails are sent through it now.';
            $notice = new ProviderRemovalNotice(false, "{$disabled}{$webhook}");
        }

        return $notice;
    }


    # Accessors
    public function isRefused() : bool { return $this->m_isRefused; }
    public function getMessage() : string { return $this->m_message; }
}
