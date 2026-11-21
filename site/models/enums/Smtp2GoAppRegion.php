<?php

/*
 * The regions of SMTP2GO's web app, each on a host of its own, as an account is logged in to on its region's.
 */

declare(strict_types = 1);


enum Smtp2GoAppRegion : string
{
    case EU = 'app-eu.smtp2go.com';
    case US = 'app-us.smtp2go.com';
    case AUSTRALIA = 'app-au.smtp2go.com';
    case GLOBAL = 'app.smtp2go.com';


    public function getLabel() : string
    {
        return match ($this) {
            Smtp2GoAppRegion::EU => 'EU',
            Smtp2GoAppRegion::US => 'US',
            Smtp2GoAppRegion::AUSTRALIA => 'Australia',
            Smtp2GoAppRegion::GLOBAL => 'Global',
        };
    }


    /**
     * Get the address of this region's webhooks page.
     * @return string
     */
    public function getWebhooksUrl() : string
    {
        return "https://{$this->value}/settings/webhooks/";
    }
}
