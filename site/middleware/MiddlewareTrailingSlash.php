<?php

use Programster\Http\HttpCode;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

class MiddlewareTrailingSlash implements \Psr\Http\Server\MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $uri = $request->getUri();
        $path = $uri->getPath();

        if ($path != '/' && substr($path, -1) == '/')
        {
            // redirect paths with a trailing slash
            // to their non-trailing counterpart
            $uri = $uri->withPath(substr($path, 0, -1));

            if ($request->getMethod() == 'GET')
            {
                $response = new \Slim\Psr7\Response(HttpCode::TEMPORARY_REDIRECT->value);
                $response = $response->withHeader('Location', (string)$uri);
            }
            else
            {
                $request = $request->withUri($uri);
                $response = $handler->handle($request);
            }
        }
        else
        {
            // do nothing. Just pass through.
            $response = $handler->handle($request);
        }

        return $response;
    }
}
