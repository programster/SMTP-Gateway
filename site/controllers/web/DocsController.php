<?php

/*
 * The public API documentation: the OpenAPI document, and Redoc and Swagger UI to read it with.
 */

declare(strict_types = 1);

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Interfaces\RouteCollectorProxyInterface;


class DocsController extends AbstractController
{
    // The documentation pages load Redoc or Swagger UI from jsDelivr, which also need inline styles and web workers.
    private const string DOCS_CSP =
        "default-src 'self'; script-src 'self' https://cdn.jsdelivr.net; style-src 'self' 'unsafe-inline'"
        . " https://cdn.jsdelivr.net https://fonts.googleapis.com; font-src 'self' https://fonts.gstatic.com;"
        . " img-src 'self' data: https://cdn.jsdelivr.net; worker-src 'self' blob:; connect-src 'self';"
        . " frame-ancestors 'none'; base-uri 'none'; object-src 'none'";


    public static function registerRoutes(RouteCollectorProxyInterface $group) : void
    {
        $group->get('/openapi.json', [self::class, 'getOpenApiDocument'])->setName('openapi');
        $group->get('/docs', [self::class, 'showRedoc'])->setName('docs');
        $group->get('/docs/swagger', [self::class, 'showSwagger'])->setName('docs.swagger');
    }


    public function getOpenApiDocument(Request $request, Response $response) : Response
    {
        $response->getBody()->write(OpenApiDocument::create()->toJson());

        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Access-Control-Allow-Origin', '*');
    }


    public function showRedoc(Request $request, Response $response) : Response
    {
        $response->getBody()->write((string)new ViewRedocPage());
        return $response->withHeader('Content-Security-Policy', self::DOCS_CSP);
    }


    public function showSwagger(Request $request, Response $response) : Response
    {
        $response->getBody()->write((string)new ViewSwaggerPage());
        return $response->withHeader('Content-Security-Policy', self::DOCS_CSP);
    }
}
