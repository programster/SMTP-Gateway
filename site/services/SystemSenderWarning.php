<?php

/*
 * Whether the service's own emails (e.g. password reset links, from the address on the Settings page) can be sent: if
 * every enabled provider is restricted to other senders, none takes them, so they'd wait in the queue. The dashboard
 * and the Providers page warn about it.
 */

declare(strict_types = 1);


final class SystemSenderWarning
{
    /**
     * Describe the problem, if there is one.
     * @param ProvidersConfig $config - the providers.
     * @return string - the warning, or an empty string if an enabled provider takes them (or none are sent yet).
     */
    public static function describe(ProvidersConfig $config) : string
    {
        $warning = '';

        try
        {
            $sending = Settings::get()->getSending();

            if ($sending->hasSystemEmailFrom())
            {
                $from = $sending->getSystemEmailFrom();
                $eligibility = new ProviderEligibility(new AnyProvider(), new EmailAddressCollection($from));

                if (count($eligibility->filter($config->getEnabledProviders())) === 0)
                {
                    $warning =
                        "No enabled provider takes email from {$from}, the address this service sends its own emails"
                        . ' from (e.g. password reset links), so they wait in the queue until one does. Let a'
                        . ' provider take it, or change the address on the Settings page.';
                }
            }
        }
        catch (ConfigurationException)
        {
            // The settings can't be read, which the Settings page explains.
        }

        return $warning;
    }
}
