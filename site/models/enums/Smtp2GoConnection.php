<?php

/*
 * The two ways of connecting an SMTP2GO provider, which its form asks to choose between first: one API key that does
 * everything (the default), or an SMTP login, with the webhook set up by hand.
 */

declare(strict_types = 1);


enum Smtp2GoConnection : string
{
    public const string INPUT_NAME = 'smtp2go_connection';

    case API_KEY = 'api_key';
    case SMTP = 'smtp';


    public function getTitle() : string
    {
        return match ($this) {
            Smtp2GoConnection::API_KEY => 'API key (recommended)',
            Smtp2GoConnection::SMTP => 'SMTP username and password',
        };
    }


    public function getDescription() : string
    {
        $apiKey =
            'One key does the sending and sender checks, and can set up the webhook for you. It can\'t send an email'
            . ' whose To or Cc lists someone who isn\'t a recipient, as mailing-list software and some mail-merge'
            . ' tools do.';

        $smtp = 'Can send any email. You generate a webhook secret here, and add the webhook in SMTP2GO by hand.';

        return match ($this) {
            Smtp2GoConnection::API_KEY => $apiKey,
            Smtp2GoConnection::SMTP => $smtp,
        };
    }


    /**
     * Get the permissions an API key needs when connecting this way.
     * @return string
     */
    public function getApiKeyPermissions() : string
    {
        $all = 'give it /email/mime, /domain/view, /single_sender_emails/view, /webhook/view, /webhook/add and'
            . ' /webhook/edit.';

        $checksOnly = 'only for the sender checks, so give it just /domain/view and /single_sender_emails/view.';

        return match ($this) {
            Smtp2GoConnection::API_KEY => $all,
            Smtp2GoConnection::SMTP => $checksOnly,
        };
    }


    /**
     * Check whether one of the provider's settings is on the form when connecting this way.
     *
     * @param string $settingKey - the setting, e.g. "smtp_username".
     *
     * @return bool
     */
    public function showsSetting(string $settingKey) : bool
    {
        return ($this === Smtp2GoConnection::SMTP || $settingKey === 'api_key');
    }
}
