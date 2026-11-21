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
        $isNewSecret = ($secret === '');

        if ($isNewSecret)
        {
            $secret = bin2hex(random_bytes(self::SECRET_BYTES));

            // Saved first, so the webhook's first event is accepted.
            ProvidersFormService::setWebhookSecret($name, $secret, $version);
        }

        try
        {
            $installer->install($webhookUrl, $secret);
        }
        catch (InvalidInputException $e)
        {
            // Without a webhook it's no use, and keeping it would hide the warning that events aren't being recorded.
            if ($isNewSecret)
            {
                $currentVersion = SettingsFile::getSectionVersion(SettingsSection::PROVIDERS);
                ProvidersFormService::setWebhookSecret($name, '', $currentVersion);
            }

            throw $e;
        }
    }


    /**
     * Generate a provider's webhook token (its webhook_secret) and save it, for entering in SMTP2GO by hand. It's only
     * returned here, and can't be shown again. Replacing a token stops a webhook using the old one until it's updated
     * in SMTP2GO, so that has to be confirmed.
     *
     * @param string $name - the provider's name.
     *
     * @param string $version - the version of the providers the change is based on.
     *
     * @param bool $isReplacementConfirmed - whether the user has confirmed replacing a token it already has.
     *
     * @return string - the new token.
     *
     * @throws NotFoundException - if there's no such provider.
     * @throws InvalidInputException - if it isn't an SMTP2GO provider, or replacing its token wasn't confirmed.
     * @throws ConflictException - if the providers have changed since the version given.
     * @throws ConfigurationException - if the providers can't be read or saved.
     */
    public static function generateToken(string $name, string $version, bool $isReplacementConfirmed) : string
    {
        $settings = ProvidersFormService::loadProvider($name)->getSettings();

        if (!($settings instanceof Smtp2GoSettings))
        {
            throw new InvalidInputException("The provider \"{$name}\" isn't an SMTP2GO provider.");
        }

        if ($settings->getWebhookSecret() !== '' && $isReplacementConfirmed === false)
        {
            throw new InvalidInputException('Tick the box to confirm replacing the webhook token it already has.');
        }

        $token = bin2hex(random_bytes(self::SECRET_BYTES));
        ProvidersFormService::setWebhookSecret($name, $token, $version);
        return $token;
    }
}
