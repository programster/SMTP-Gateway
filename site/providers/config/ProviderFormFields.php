<?php

/*
 * The fields of each type of provider on the provider form, in the order they're shown, which is the order of each
 * type's settings in settings.yaml. ProvidersConfigParser is what checks them: these only say how to show them.
 * ProviderFormTest checks they're exactly each type's settings, so adding a setting to a type needs it adding here.
 */

declare(strict_types = 1);


final class ProviderFormFields
{
    private const string WEBHOOK_SECRET_HINT =
        'Optional. The secret its webhook authenticates with, which switches its events on (see the documentation).';


    /**
     * Get a type of provider's fields.
     *
     * @param ProviderType $type - the type.
     *
     * @return ProviderFieldCollection
     */
    public static function getForType(ProviderType $type) : ProviderFieldCollection
    {
        return match ($type) {
            ProviderType::SENDGRID => self::getSendGridFields(),
            ProviderType::MAILGUN => self::getMailgunFields(),
            ProviderType::POSTMARK => self::getPostmarkFields(),
            ProviderType::BREVO => self::getBrevoFields(),
            ProviderType::MAILJET => self::getMailjetFields(),
            ProviderType::MAILERSEND => self::getMailerSendFields(),
            ProviderType::MAILTRAP => self::getMailtrapFields(),
            ProviderType::CUSTOM => self::getCustomFields(),
        };
    }


    private static function getSendGridFields() : ProviderFieldCollection
    {
        $publicKeyHint =
            'Optional. The Verification Key SendGrid shows for its event webhook once signature verification is on.'
            . ' Until it\'s set, no events (deliveries, bounces, etc.) are recorded.';

        return new ProviderFieldCollection(
            ProviderField::createSecret('api_key', 'API key', true, 'An API key with the Mail Send permission.'),
            ProviderField::createTextarea('webhook_public_key', 'Webhook public key', false, $publicKeyHint),
        );
    }


    private static function getMailgunFields() : ProviderFieldCollection
    {
        $regions = [MailgunRegion::US->value => 'US', MailgunRegion::EU->value => 'EU'];
        $usernameHint = 'One of a sending domain\'s SMTP logins, e.g. postmaster@mg.example.org.';
        $apiKeyHint = 'Optional. For the sender checks: it only needs to read domains and webhooks.';
        $signingKeyHint = 'Optional. The HTTP webhook signing key (Settings > API Security), which switches events on.';

        return new ProviderFieldCollection(
            ProviderField::createChoice('region', 'Region', 'The region its domain is in.', $regions, 'us'),
            ProviderField::createText('smtp_username', 'SMTP username', true, $usernameHint),
            ProviderField::createSecret('smtp_password', 'SMTP password', true, ''),
            ProviderField::createSecret('api_key', 'API key', false, $apiKeyHint),
            ProviderField::createSecret('webhook_signing_key', 'Webhook signing key', false, $signingKeyHint),
        );
    }


    private static function getPostmarkFields() : ProviderFieldCollection
    {
        $serverTokenHint = 'A server\'s API token, which is also its SMTP login.';
        $accountTokenHint = 'Optional. An account API token, for reading its domains and senders (the sender checks).';

        return new ProviderFieldCollection(
            ProviderField::createSecret('server_token', 'Server token', true, $serverTokenHint),
            ProviderField::createText('message_stream', 'Message stream', true, 'The stream\'s ID.', 'outbound'),
            ProviderField::createSecret('account_token', 'Account token', false, $accountTokenHint),
            ProviderField::createSecret('webhook_secret', 'Webhook secret', false, self::WEBHOOK_SECRET_HINT),
        );
    }


    private static function getBrevoFields() : ProviderFieldCollection
    {
        $loginHint = 'The login Brevo shows under SMTP & API > SMTP.';
        $apiKeyHint = 'Optional. For the sender checks.';

        return new ProviderFieldCollection(
            ProviderField::createText('smtp_login', 'SMTP login', true, $loginHint),
            ProviderField::createSecret('smtp_key', 'SMTP key', true, 'An SMTP key, not an API key.'),
            ProviderField::createSecret('api_key', 'API key', false, $apiKeyHint),
            ProviderField::createSecret('webhook_secret', 'Webhook secret', false, self::WEBHOOK_SECRET_HINT),
        );
    }


    private static function getMailjetFields() : ProviderFieldCollection
    {
        $apiKeyHint = 'The (public) API key, under Account settings > API keys. It\'s also the SMTP username.';
        $secretKeyHint = 'The API key\'s secret key. It\'s also the SMTP password.';

        return new ProviderFieldCollection(
            ProviderField::createText('api_key', 'API key', true, $apiKeyHint),
            ProviderField::createSecret('secret_key', 'Secret key', true, $secretKeyHint),
            ProviderField::createSecret('webhook_secret', 'Webhook secret', false, self::WEBHOOK_SECRET_HINT),
        );
    }


    private static function getMailerSendFields() : ProviderFieldCollection
    {
        $usernameHint = 'One of the account\'s SMTP users, under Domains > the domain > SMTP.';
        $apiTokenHint = 'Optional. For the sender checks: read access to domains, and full access to webhooks.';

        return new ProviderFieldCollection(
            ProviderField::createText('smtp_username', 'SMTP username', true, $usernameHint),
            ProviderField::createSecret('smtp_password', 'SMTP password', true, ''),
            ProviderField::createSecret('api_token', 'API token', false, $apiTokenHint),
            ProviderField::createSecret('webhook_secret', 'Webhook secret', false, self::WEBHOOK_SECRET_HINT),
        );
    }


    private static function getMailtrapFields() : ProviderFieldCollection
    {
        $smtpTokenHint = 'One of a sending domain\'s API tokens, which is its SMTP password.';
        $apiTokenHint = 'Optional. With at least viewer access to the account, for the sender checks.';

        return new ProviderFieldCollection(
            ProviderField::createSecret('smtp_token', 'SMTP token', true, $smtpTokenHint),
            ProviderField::createSecret('api_token', 'API token', false, $apiTokenHint),
            ProviderField::createSecret('webhook_secret', 'Webhook secret', false, self::WEBHOOK_SECRET_HINT),
        );
    }


    private static function getCustomFields() : ProviderFieldCollection
    {
        $security = [
            SmtpSecurity::TLS->value => 'STARTTLS (usually port 587)',
            SmtpSecurity::SSL->value => 'Implicit TLS (usually port 465)',
            SmtpSecurity::NONE->value => 'None: unencrypted',
        ];

        $usernameHint = 'Optional, for a server that needs a login.';
        $passwordHint = 'Needed when there\'s a username.';
        $dkimHint = 'Optional. The DKIM selector it signs with, for the sender checks, e.g. "dkim".';

        return new ProviderFieldCollection(
            ProviderField::createText('host', 'Host', true, 'e.g. smtp.example.org'),
            ProviderField::createNumber('port', 'Port', '', 587),
            ProviderField::createChoice('security', 'Security', '', $security, SmtpSecurity::TLS->value),
            ProviderField::createText('username', 'Username', false, $usernameHint),
            ProviderField::createSecret('password', 'Password', false, $passwordHint),
            ProviderField::createText('dkim_selector', 'DKIM selector', false, $dkimHint),
        );
    }
}
