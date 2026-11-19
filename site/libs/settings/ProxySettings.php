<?php

/*
 * The proxies section of settings.yaml: what sits in front of the web interface, so a request's real IP address can be
 * worked out (see ClientIpResolver). Whether the site is behind Cloudflare (whose addresses are fetched from
 * Cloudflare, see CloudflareNetworks, rather than written here), and your own reverse proxies, whose X-Forwarded-For
 * header is believed.
 */

declare(strict_types = 1);


readonly class ProxySettings
{
    private bool $m_isBehindCloudflare;
    private IpNetworkCollection $m_trustedProxies;


    /**
     * @param bool $isBehindCloudflare - whether requests come through Cloudflare, so that its CF-Connecting-IP header
     * is believed when a request comes from one of Cloudflare's addresses (see ClientIpResolver).
     *
     * @param IpNetworkCollection $trustedProxies - your own reverse proxies in front of the web interface (between it
     * and Cloudflare, if it's behind Cloudflare), whose X-Forwarded-For header is believed. Anything else's is
     * ignored, so a client can't fake its own address. Cloudflare's addresses don't go here.
     *
     * @throws InvalidInputException - if a proxy's range covers every address (a prefix length of 0, e.g.
     * "0.0.0.0/0"), as that would let any client fake its address.
     */
    public function __construct(bool $isBehindCloudflare, IpNetworkCollection $trustedProxies)
    {
        foreach ($trustedProxies as $proxy)
        {
            /* @var $proxy IpNetwork */
            if ($proxy->getPrefixLength() === 0)
            {
                $msg =
                    "A trusted proxy can't be every address (\"{$proxy}\"), as then any client could fake its IP"
                    . ' address. Give the address (or range) of the proxies in front of this service.';

                throw new InvalidInputException($msg);
            }
        }

        $this->m_isBehindCloudflare = $isBehindCloudflare;
        $this->m_trustedProxies = $trustedProxies;
    }


    /**
     * Create the settings for when there's no proxies section, or it's invalid: not behind Cloudflare, and no proxies
     * trusted, so no header is believed, the safe choice.
     *
     * @return ProxySettings
     */
    public static function createDefault() : ProxySettings
    {
        return new ProxySettings(false, new IpNetworkCollection());
    }


    # Accessors
    public function isBehindCloudflare() : bool { return $this->m_isBehindCloudflare; }
    public function getTrustedProxies() : IpNetworkCollection { return $this->m_trustedProxies; }
}
