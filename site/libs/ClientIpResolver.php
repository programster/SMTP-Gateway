<?php

/*
 * Works out the IP address a web request really came from. Anyone can send the headers that say where a request came
 * from (X-Forwarded-For, and Cloudflare's CF-Connecting-IP), to fake their address, so each is only believed from
 * whoever is known to set it:
 *
 * - X-Forwarded-For, from your own reverse proxies (the trusted proxies, set on the Settings page). It's walked back
 *   from the nearest proxy, through each trusted one, to the first address that isn't one.
 * - CF-Connecting-IP, when the site is behind Cloudflare, from one of Cloudflare's addresses (see
 *   CloudflareNetworks). Cloudflare can send requests straight here, or through your proxies, so the address reached
 *   by the walk above is checked too: if it's Cloudflare's, the client is the one Cloudflare says.
 *
 * The response goes back the way the request came (over the same connection, to your proxy, then to Cloudflare), so
 * only where it came from needs working out.
 */

declare(strict_types = 1);

use Psr\Http\Message\ServerRequestInterface;


final class ClientIpResolver
{
    /**
     * Get the client's IP address.
     *
     * @param ServerRequestInterface $request - the request.
     *
     * @return IpAddress
     *
     * @throws InvalidInputException - if the connection's address can't be read (which shouldn't happen).
     */
    public static function resolve(ServerRequestInterface $request) : IpAddress
    {
        $proxies = Settings::get()->getProxies();

        $cloudflare = $proxies->isBehindCloudflare()
            ? CloudflareNetworks::loadNetworks()
            : new IpNetworkCollection();

        return self::resolveFrom(
            new IpAddress((string)($request->getServerParams()['REMOTE_ADDR'] ?? '')),
            $request->getHeaderLine('X-Forwarded-For'),
            $request->getHeaderLine('CF-Connecting-IP'),
            $proxies->getTrustedProxies(),
            $cloudflare
        );
    }


    /**
     * Work out the client's IP address from where the connection came from, and the headers that say where it came
     * from before that (see the class's comment).
     *
     * @param IpAddress $connection - the address the connection came from (REMOTE_ADDR).
     *
     * @param string $forwardedFor - the X-Forwarded-For header, or an empty string.
     *
     * @param string $cloudflareClient - the CF-Connecting-IP header, or an empty string.
     *
     * @param IpNetworkCollection $trustedProxies - your own reverse proxies.
     *
     * @param IpNetworkCollection $cloudflare - Cloudflare's ranges, or none, if the site isn't behind Cloudflare.
     *
     * @return IpAddress
     */
    public static function resolveFrom(
        IpAddress $connection,
        string $forwardedFor,
        string $cloudflareClient,
        IpNetworkCollection $trustedProxies,
        IpNetworkCollection $cloudflare
    ) : IpAddress
    {
        $client = $connection;
        $hops = ($forwardedFor === '') ? [] : array_reverse(array_map('trim', explode(',', $forwardedFor)));
        $index = 0;
        $isWalking = true;

        // Walk back along the chain of your proxies, from the nearest, to the first address that isn't one. Anything
        // before it could have been made up by the client. A hop that isn't an address ends the walk there.
        while ($isWalking && $index < count($hops) && self::isWithin($client, $trustedProxies))
        {
            $isWalking = IpAddress::isValid($hops[$index]);

            if ($isWalking)
            {
                $client = new IpAddress($hops[$index]);
            }

            $index++;
        }

        if (IpAddress::isValid($cloudflareClient) && self::isWithin($client, $cloudflare))
        {
            $client = new IpAddress($cloudflareClient);
        }

        return $client;
    }


    /**
     * Whether the request came over HTTPS, either directly, or to one of your proxies or Cloudflare, which say so
     * with X-Forwarded-Proto.
     *
     * @param ServerRequestInterface $request - the request.
     *
     * @return bool
     */
    public static function isHttps(ServerRequestInterface $request) : bool
    {
        $server = $request->getServerParams();
        $proxies = Settings::get()->getProxies();
        $isDirectHttps = (isset($server['HTTPS']) && $server['HTTPS'] !== '' && $server['HTTPS'] !== 'off');
        $remoteAddress = new IpAddress((string)($server['REMOTE_ADDR'] ?? ''));

        $isFromProxy = (
               self::isWithin($remoteAddress, $proxies->getTrustedProxies())
            || ($proxies->isBehindCloudflare() && self::isWithin($remoteAddress, CloudflareNetworks::loadNetworks()))
        );

        $isProxiedHttps = ($isFromProxy && strtolower($request->getHeaderLine('X-Forwarded-Proto')) === 'https');
        return $isDirectHttps || $isProxiedHttps;
    }


    private static function isWithin(IpAddress $address, IpNetworkCollection $networks) : bool
    {
        $isWithin = false;

        foreach ($networks as $network)
        {
            /* @var $network IpNetwork */
            $isWithin = ($isWithin || $network->contains($address));
        }

        return $isWithin;
    }
}
