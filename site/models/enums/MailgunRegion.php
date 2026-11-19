<?php

/*
 * The region a Mailgun account's domains are in, which sets the SMTP relay and API it's reached through. A domain's
 * SMTP credentials, events and webhooks all belong to its region.
 */

declare(strict_types = 1);


enum MailgunRegion : string
{
    case US = 'us';
    case EU = 'eu';


    public function getSmtpHost() : string
    {
        return match ($this) {
            MailgunRegion::US => 'smtp.mailgun.org',
            MailgunRegion::EU => 'smtp.eu.mailgun.org',
        };
    }


    public function getApiUrl() : string
    {
        return match ($this) {
            MailgunRegion::US => 'https://api.mailgun.net',
            MailgunRegion::EU => 'https://api.eu.mailgun.net',
        };
    }
}
