<?php

/*
 * Where a provider should send its events: PUBLIC_URL/webhooks/<provider name> (see WebhookController).
 */

declare(strict_types = 1);


final class ProviderWebhookUrl
{
    /**
     * Get the address of a provider's webhook.
     *
     * @param string $providerName - the provider's name in settings.yaml.
     *
     * @return string - the address, or an empty string if PUBLIC_URL isn't set, so it isn't known.
     */
    public static function get(string $providerName) : string
    {
        try
        {
            $url = Config::get()->getPublicUrl() . '/webhooks/' . rawurlencode($providerName);
        }
        catch (ConfigurationException)
        {
            $url = '';
        }

        return $url;
    }
}
