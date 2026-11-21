<?php

/*
 * The button on an SMTP2GO provider's page that sets up its webhook: it makes the provider a webhook_secret if it has
 * none, then creates the webhook in the SMTP2GO account with it, or updates the one that's already there.
 */

declare(strict_types = 1);


final class Smtp2GoWebhookSetup
{
    private const int SECRET_BYTES = 24;


    /**
     * Set up a provider's webhook.
     *
     * @param string $name - the provider's name.
     *
     * @param string $version - the version of the providers the change is based on.
     *
     * @return void
     *
     * @throws NotFoundException - if there's no such provider.
     * @throws InvalidInputException - if it can't be set up this way, or SMTP2GO refused, saying why.
     * @throws ConflictException - if the providers have changed since the version given.
     * @throws ConfigurationException - if the providers can't be read or saved.
     */
    public static function run(string $name, string $version) : void
    {
        $settings = ProvidersFormService::loadProvider($name)->getSettings();

        if (!($settings instanceof Smtp2GoSettings))
        {
            throw new InvalidInputException("The provider \"{$name}\" isn't an SMTP2GO provider.");
        }

        $installer = $settings->createWebhookInstaller();
        $webhookUrl = ProviderWebhookUrl::get($name);
        $secret = $settings->getWebhookSecret();

        if ($secret === '')
        {
            $secret = bin2hex(random_bytes(self::SECRET_BYTES));

            // Saved first, so the webhook's first event is accepted.
            ProvidersFormService::setWebhookSecret($name, $secret, $version);
        }

        $installer->install($webhookUrl, $secret);
    }
}
