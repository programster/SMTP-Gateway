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
        $base = self::getBase();
        $url = ($base === '') ? '' : $base . rawurlencode($providerName);
        return $url;
    }


    /**
     * Get the start of every provider's webhook address, which its name goes on the end of, e.g. for the provider
     * form to add the name to as it's typed.
     *
     * @return string - PUBLIC_URL/webhooks/ (with the trailing slash), or an empty string if PUBLIC_URL isn't set,
     * so it isn't known.
     */
    public static function getBase() : string
    {
        try
        {
            $base = Config::get()->getPublicUrl() . '/webhooks/';
        }
        catch (ConfigurationException)
        {
            $base = '';
        }

        return $base;
    }
}
