<?php

/*
 * Checks a sending domain's own DNS, whichever provider sends its email: that it has a DMARC policy, a single SPF
 * record that doesn't let anyone send as it, and somewhere to receive email (some receivers refuse email from a
 * domain that couldn't receive a reply).
 *
 * Whether each provider is allowed to send as the domain (e.g. SendGrid's domain authentication) is checked by that
 * provider's SenderCheckerInterface.
 */

declare(strict_types = 1);


final class DomainDnsChecker
{
    // The source of every check it makes (see SenderCheck).
    public const string SOURCE = 'DNS';

    // Well-known domains people have mailboxes at, but whose DNS nobody else controls, so they can never be
    // authenticated with a provider.
    private const array FREE_MAIL_DOMAINS = [
        'gmail.com', 'googlemail.com', 'outlook.com', 'hotmail.com', 'hotmail.co.uk', 'live.com', 'msn.com',
        'yahoo.com', 'yahoo.co.uk', 'ymail.com', 'aol.com', 'icloud.com', 'me.com', 'mac.com', 'proton.me',
        'protonmail.com', 'gmx.com', 'gmx.de', 'web.de', 'mail.com', 'zoho.com', 'yandex.com', 'btinternet.com',
    ];


    /**
     * Check a domain's DNS.
     *
     * @param DomainDns $dns - the domain's records.
     *
     * @return SenderCheckCollection
     */
    public static function check(DomainDns $dns) : SenderCheckCollection
    {
        $checks = [];

        if (self::isFreeMailDomain($dns->getDomain()))
        {
            $detail =
                "Nobody but its owner can change {$dns->getDomain()}'s DNS, so it can't be authenticated with any"
                . ' provider. Email sent through a provider that claims to be from it fails DMARC, and is likely to'
                . ' go to spam or be refused, depending on the policy below. Send from a domain you own instead.';

            $checks[] = new SenderCheck(SenderCheckLevel::WARNING, self::SOURCE, 'A free email domain', $detail);
        }

        $checks[] = self::checkDmarc($dns);
        $checks[] = self::checkSpf($dns);

        if ($dns->hasMailHost() === false)
        {
            $detail =
                "{$dns->getDomain()} has no MX (or address) record, so it can't receive email. Some receivers refuse"
                . ' email from a domain that couldn\'t receive a reply, and bounces and replies to it can\'t arrive.';

            $checks[] = new SenderCheck(SenderCheckLevel::WARNING, self::SOURCE, 'It can\'t receive email', $detail);
        }

        return new SenderCheckCollection(...$checks);
    }


    /**
     * Check whether a domain is a well-known free email domain, such as gmail.com.
     *
     * @param string $domain - the domain.
     *
     * @return bool
     */
    public static function isFreeMailDomain(string $domain) : bool
    {
        return in_array(strtolower($domain), self::FREE_MAIL_DOMAINS, true);
    }


    private static function checkDmarc(DomainDns $dns) : SenderCheck
    {
        $records = $dns->getDmarcRecords();
        $location = $dns->getDmarcLocation();
        $where = ($location === $dns->getDomain()) ? "_dmarc.{$location}" : "_dmarc.{$location} (its parent's)";
        $example = '"v=DMARC1; p=none; rua=mailto:dmarc-reports@' . $dns->getDomain() . '"';
        $isOwnable = (self::isFreeMailDomain($dns->getDomain()) === false);

        if (count($records) === 0 && $isOwnable === false)
        {
            $detail = "{$dns->getDomain()} has no DMARC policy, so receivers decide for themselves.";
            $check = new SenderCheck(SenderCheckLevel::INFO, self::SOURCE, 'No DMARC record', $detail);
        }
        elseif (count($records) === 0)
        {
            $detail =
                "Gmail and Yahoo require one from anyone sending them more than a few thousand emails a day, and"
                . " other receivers trust email more with one. Add a TXT record at _dmarc.{$dns->getDomain()}, e.g."
                . " {$example}, and tighten p= to quarantine or reject once its reports show only your own email.";

            $check = new SenderCheck(SenderCheckLevel::WARNING, self::SOURCE, 'No DMARC record', $detail);
        }
        elseif (count($records) > 1)
        {
            $detail =
                "There are " . count($records) . " DMARC records at {$where}, so receivers ignore them all, as if"
                . ' there were none. Merge them into one.';

            $check = new SenderCheck(SenderCheckLevel::ERROR, self::SOURCE, 'More than one DMARC record', $detail);
        }
        elseif ($dns->getDmarcPolicy() === DmarcPolicy::MISSING)
        {
            $detail =
                "The record at {$where} (\"{$records[0]}\") has no valid p= tag (none, quarantine or reject), so"
                . ' receivers ignore it.';

            $check = new SenderCheck(SenderCheckLevel::ERROR, self::SOURCE, 'An invalid DMARC record', $detail);
        }
        elseif ($dns->getDmarcPolicy() === DmarcPolicy::NONE && $isOwnable === false)
        {
            $detail =
                "The record at {$where} only asks for reports for now, so receivers may still deliver email that fails"
                . ' DMARC, though likely to spam.';

            $title = 'DMARC is only monitoring (p=none)';
            $check = new SenderCheck(SenderCheckLevel::INFO, self::SOURCE, $title, $detail);
        }
        elseif ($dns->getDmarcPolicy() === DmarcPolicy::NONE)
        {
            $detail =
                "The record at {$where} only asks for reports, so email forged as coming from {$dns->getDomain()}"
                . ' is still delivered. Once the reports show that only your own email is passing, change p= to'
                . ' quarantine, then reject.';

            $title = 'DMARC is only monitoring (p=none)';
            $check = new SenderCheck(SenderCheckLevel::INFO, self::SOURCE, $title, $detail);
        }
        else
        {
            $policy = $dns->getDmarcPolicy()->value;
            $detail = "The record at {$where} asks receivers to {$policy} email that fails DMARC.";
            $check = new SenderCheck(SenderCheckLevel::OK, self::SOURCE, "DMARC is enforced (p={$policy})", $detail);
        }

        return $check;
    }


    private static function checkSpf(DomainDns $dns) : SenderCheck
    {
        $records = $dns->getSpfRecords();

        if (count($records) === 0)
        {
            $detail =
                "{$dns->getDomain()} has no SPF record. That's fine for a provider that uses its own return-path"
                . ' subdomain (as SendGrid does once the domain is authenticated), but any server that sends with'
                . ' this domain in the return-path needs to be listed in one.';

            $check = new SenderCheck(SenderCheckLevel::INFO, self::SOURCE, 'No SPF record', $detail);
        }
        elseif (count($records) > 1)
        {
            $detail =
                "There are " . count($records) . " SPF records at {$dns->getDomain()}, which is an error (a"
                . ' "permerror") to receivers, so SPF fails for everything sent as it. Merge them into one record,'
                . ' with every include: in it.';

            $check = new SenderCheck(SenderCheckLevel::ERROR, self::SOURCE, 'More than one SPF record', $detail);
        }
        elseif (preg_match('/(^|\s)\+?all(\s|$)/i', $records[0]) === 1)
        {
            $detail =
                "The SPF record (\"{$records[0]}\") ends with \"+all\", which lets any server in the world send as"
                . ' this domain. End it with "~all" or "-all" instead.';

            $check = new SenderCheck(SenderCheckLevel::WARNING, self::SOURCE, 'SPF allows anyone', $detail);
        }
        else
        {
            $check = new SenderCheck(SenderCheckLevel::OK, self::SOURCE, 'One SPF record', "\"{$records[0]}\"");
        }

        return $check;
    }
}
