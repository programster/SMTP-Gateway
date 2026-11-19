<?php

/*
 * Why the dashboard warns that the service may not be seeing people's real addresses (see ProxyWarning), or that it
 * doesn't.
 */

declare(strict_types = 1);


enum ProxyWarningKind
{
    // Nothing to warn about: the address seen is a public one.
    case NONE;

    // The request came from a private address that isn't a trusted proxy, but which says who it's forwarding for, so
    // it's almost certainly a reverse proxy that needs adding to the trusted proxies.
    case UNTRUSTED_PROXY;

    // The request says it came through Cloudflare, but the site isn't set as being behind Cloudflare.
    case CLOUDFLARE;

    // The request was forwarded (it has an X-Forwarded-For or CF-Connecting-IP header), but the address the service
    // takes to be the client's is still a private one, e.g. as only some of the proxies it came through are trusted.
    case PRIVATE_ADDRESS;
}
