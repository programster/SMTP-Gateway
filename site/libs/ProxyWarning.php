<?php

/*
 * Whether the service seems not to be seeing people's real IP addresses, for the warning at the top of the dashboard.
 * Behind a reverse proxy, or Cloudflare, that isn't set up under Settings > Proxies, every request seems to come from
 * the proxy, so the logs show the wrong addresses, IP address restrictions don't work, and the limit on failed logins
 * would count everyone's failures against the proxy, locking everyone out at once.
 *
 * It looks at the request being shown the dashboard: the address the connection came from, the address the service
 * takes to be the client's (see ClientIpResolver), and the headers a proxy adds. Only a request with one of those
 * headers is warned about, so a service reached directly, inside a home or office network, never is.
 */

declare(strict_types = 1);

use Psr\Http\Message\ServerRequestInterface;


final readonly class ProxyWarning
{
    // The cookie that hides the warning in a browser, for a service that's reached directly inside a private network.
    public const string DISMISSED_COOKIE = 'proxy_warning_dismissed';

    private ProxyWarningKind $m_kind;
    private IpAddress $m_clientIp;
    private IpAddress $m_connectionIp;


    /**
     * @param ProxyWarningKind $kind - what's wrong, if anything.
     *
     * @param IpAddress $clientIp - the address the service takes to be the client's.
     *
     * @param IpAddress $connectionIp - the address the connection came from (REMOTE_ADDR), which is the proxy to
     * trust, for UNTRUSTED_PROXY.
     */
    private function __construct(ProxyWarningKind $kind, IpAddress $clientIp, IpAddress $connectionIp)
    {
        $this->m_kind = $kind;
        $this->m_clientIp = $clientIp;
        $this->m_connectionIp = $connectionIp;
    }


    /**
     * Work out whether to warn, from a request. A browser that's dismissed the warning (with DISMISSED_COOKIE) is
     * never warned.
     *
     * @param ServerRequestInterface $request - the request.
     *
     * @return ProxyWarning
     */
    public static function createForRequest(ServerRequestInterface $request) : ProxyWarning
    {
        $proxies = Settings::get()->getProxies();
        $connectionIp = new IpAddress((string)($request->getServerParams()['REMOTE_ADDR'] ?? ''));
        $clientIp = ClientIpResolver::resolve($request);
        $isDismissed = (($request->getCookieParams()[self::DISMISSED_COOKIE] ?? '') === '1');

        $isUntrustedProxy = (
               $request->getHeaderLine('X-Forwarded-For') !== ''
            && $connectionIp->isPrivate()
            && $proxies->getTrustedProxies()->includesAddress($connectionIp) === false
        );

        $isUnsetCloudflare = ($request->getHeaderLine('CF-Connecting-IP') !== '' && !$proxies->isBehindCloudflare());

        // A private address is only a sign of a proxy if the request says it was forwarded: without either header,
        // it's someone reaching the service directly, inside a home or office network.
        $isForwarded = (
               $request->getHeaderLine('X-Forwarded-For') !== ''
            || $request->getHeaderLine('CF-Connecting-IP') !== ''
        );

        if ($isDismissed)
        {
            $kind = ProxyWarningKind::NONE;
        }
        elseif ($isUntrustedProxy)
        {
            $kind = ProxyWarningKind::UNTRUSTED_PROXY;
        }
        elseif ($isUnsetCloudflare)
        {
            $kind = ProxyWarningKind::CLOUDFLARE;
        }
        elseif ($isForwarded && $clientIp->isPrivate())
        {
            $kind = ProxyWarningKind::PRIVATE_ADDRESS;
        }
        else
        {
            $kind = ProxyWarningKind::NONE;
        }

        return new ProxyWarning($kind, $clientIp, $connectionIp);
    }


    # Accessors
    public function getKind() : ProxyWarningKind { return $this->m_kind; }
    public function getClientIp() : IpAddress { return $this->m_clientIp; }
    public function getConnectionIp() : IpAddress { return $this->m_connectionIp; }
}
