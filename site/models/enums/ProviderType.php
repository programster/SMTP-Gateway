<?php

/*
 * The types of provider ("driver") that email can be sent through, as the "type" of each provider in settings.yaml.
 * To add one, implement SmtpProviderInterface, add a case here, add a settings class implementing
 * ProviderSettingsInterface, read it in ProvidersConfigParser, and add its fields to ProviderFormFields, for the
 * provider form.
 */

declare(strict_types = 1);


enum ProviderType : string
{
    case SENDGRID = 'sendgrid';
    case MAILGUN = 'mailgun';
    case POSTMARK = 'postmark';
    case BREVO = 'brevo';
    case MAILJET = 'mailjet';
    case MAILERSEND = 'mailersend';
    case MAILTRAP = 'mailtrap';

    // An SMTP server of your own, e.g. mailcow or Postfix (or Mailpit, while developing).
    case CUSTOM = 'custom';


    /**
     * Whether this type of provider can post its events to a webhook on this service (/webhooks/<provider name>).
     *
     * @return bool
     */
    public function hasWebhook() : bool
    {
        return match ($this) {
            ProviderType::SENDGRID => true,
            ProviderType::MAILGUN => true,
            ProviderType::POSTMARK => true,
            ProviderType::BREVO => true,
            ProviderType::MAILJET => true,
            ProviderType::MAILERSEND => true,
            ProviderType::MAILTRAP => true,
            ProviderType::CUSTOM => false,
        };
    }


    /**
     * Get what it's called on the provider form, e.g. "SendGrid".
     * @return string
     */
    public function getLabel() : string
    {
        return match ($this) {
            ProviderType::SENDGRID => 'SendGrid',
            ProviderType::MAILGUN => 'Mailgun',
            ProviderType::POSTMARK => 'Postmark',
            ProviderType::BREVO => 'Brevo',
            ProviderType::MAILJET => 'Mailjet',
            ProviderType::MAILERSEND => 'MailerSend',
            ProviderType::MAILTRAP => 'Mailtrap',
            ProviderType::CUSTOM => 'Your own SMTP server',
        };
    }
}
