<?php

/*
 * Adds the security headers to every response. The Content-Security-Policy only allows this site's own scripts and
 * styles (so no inline JavaScript), unless a route has set its own (e.g. the API documentation pages, which load
 * Redoc or Swagger UI from a CDN).
 */

declare(strict_types = 1);

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;


class MiddlewareSecurityHeaders implements \Psr\Http\Server\MiddlewareInterface
{
    public const string DEFAULT_CSP =
        "default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; connect-src 'self';"
        . " frame-ancestors 'none'; form-action 'self'; base-uri 'none'; object-src 'none'";


    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler) : ResponseInterface
    {
        $response = $handler->handle($request);

        if ($response->hasHeader('Content-Security-Policy') === false)
        {
            $response = $response->withHeader('Content-Security-Policy', self::DEFAULT_CSP);
        }

        // A page can set a stricter policy of its own (e.g. a password reset link's page, whose URL holds a token).
        if ($response->hasHeader('Referrer-Policy') === false)
        {
            $response = $response->withHeader('Referrer-Policy', 'same-origin');
        }

        // A page can let this site frame it (e.g. an email's HTML, shown on the email's page).
        if ($response->hasHeader('X-Frame-Options') === false)
        {
            $response = $response->withHeader('X-Frame-Options', 'DENY');
        }

        return $response->withHeader('X-Content-Type-Options', 'nosniff');
    }
}
