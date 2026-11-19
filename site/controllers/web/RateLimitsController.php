<?php

/*
 * Viewing and changing the global rate limits: the limits on everything sent through the provider.
 */

declare(strict_types = 1);

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Interfaces\RouteCollectorProxyInterface;


class RateLimitsController extends AbstractController
{
    public static function registerRoutes(RouteCollectorProxyInterface $group) : void
    {
        $group->get('/rate-limits', [self::class, 'show'])->setName('rate-limits');
        $group->post('/rate-limits', [self::class, 'save'])->setName('rate-limits.save');
    }


    public function show(Request $request, Response $response) : Response
    {
        $page = new ViewRateLimitsPage(GlobalRateLimitRepository::load(), '');
        return self::renderPage($response, 'Global rate limits', $page);
    }


    public function save(Request $request, Response $response) : Response
    {
        $getField = function (string $name) use ($request) : string {
            return self::getFormField($request, $name);
        };

        try
        {
            $limits = PolicyInput::readRateLimitsFromForm($getField);
            GlobalRateLimitRepository::replace($limits);

            ServiceFactory::getLogger(LogCategory::ADMIN)->notice('Global rate limits changed', [
                'limits' => $limits->describe(),
                'by_user_id' => WebSession::getUser()->getId(),
            ]);

            WebSession::flash("The global rate limits are now: {$limits->describe()}.");
            $result = self::redirect($response, '/rate-limits');
        }
        catch (InvalidInputException $e)
        {
            $page = new ViewRateLimitsPage(GlobalRateLimitRepository::load(), $e->getMessage());
            $result = self::renderPage($response, 'Global rate limits', $page, 400);
        }

        return $result;
    }
}
