<?php

/*
 * The documentation, in the web interface: the README and each page in docs/ (see DocumentationPage), turned into
 * HTML. Only the pages in DocumentationPage can be shown, so no other file can be asked for.
 */

declare(strict_types = 1);

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Interfaces\RouteCollectorProxyInterface;


class DocumentationController extends AbstractController
{
    public static function registerRoutes(RouteCollectorProxyInterface $group) : void
    {
        $group->get(DocumentationPage::PATH, [self::class, 'showOverview'])->setName('documentation');
        $group->get(DocumentationPage::PATH . '/{page}', [self::class, 'show'])->setName('documentation.page');
    }


    public function showOverview(Request $request, Response $response) : Response
    {
        return self::renderDocumentation($response, DocumentationPage::OVERVIEW);
    }


    /**
     * Show one page, e.g. /documentation/rate-limits.
     *
     * @throws NotFoundException - if there's no such page.
     */
    public function show(Request $request, Response $response, array $args) : Response
    {
        $slug = (string)($args['page'] ?? '');
        $pages = DocumentationPage::cases();
        $found = null;
        $index = 0;

        while ($found === null && $index < count($pages))
        {
            $isMatch = ($pages[$index] !== DocumentationPage::OVERVIEW && $pages[$index]->getSlug() === $slug);
            $found = $isMatch ? $pages[$index] : null;
            $index++;
        }

        if ($found === null)
        {
            throw new NotFoundException('There\'s no such page of the documentation.');
        }

        return self::renderDocumentation($response, $found);
    }


    /**
     * @throws RuntimeException - if the page's file can't be read.
     */
    private static function renderDocumentation(Response $response, DocumentationPage $page) : Response
    {
        $view = new ViewDocumentationPage(DocumentationRenderer::fetchPage($page));
        return self::renderPage($response, $page->getTitle(), $view);
    }
}
