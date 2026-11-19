<?php

/*
 * What Mailgun's API says about the domain a Mailgun provider sends as (see MailgunSenderChecker).
 */

declare(strict_types = 1);


enum MailgunDomainState
{
    // It's verified and can send.
    case ACTIVE;

    // Its DNS records haven't all been found yet.
    case UNVERIFIED;

    // Mailgun has disabled it (e.g. for abuse, or an unpaid bill).
    case DISABLED;

    // It's the account's sandbox domain, which only sends to authorized recipients.
    case SANDBOX;

    // The account has no such domain (in this region).
    case MISSING;

    // The API key isn't allowed to read it.
    case UNREADABLE;

    // The provider has no API key, so it wasn't asked.
    case UNCHECKED;


    /**
     * Get the state from the "domain" in GET /v4/domains/{name}.
     *
     * @param array $domain - the domain, decoded from JSON.
     *
     * @return MailgunDomainState
     */
    public static function createFromApi(array $domain) : MailgunDomainState
    {
        $isDisabled = (($domain['is_disabled'] ?? false) === true || ($domain['state'] ?? '') === 'disabled');

        return match (true) {
            $isDisabled => MailgunDomainState::DISABLED,
            ($domain['type'] ?? '') === 'sandbox' => MailgunDomainState::SANDBOX,
            ($domain['state'] ?? '') === 'active' => MailgunDomainState::ACTIVE,
            default => MailgunDomainState::UNVERIFIED,
        };
    }
}
