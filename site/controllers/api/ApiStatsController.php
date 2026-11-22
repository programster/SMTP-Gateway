<?php

/*
 * /api/v1/stats and /api/v1/rate-limits: the statistics, and the global rate limits. Needs a management API key.
 */

declare(strict_types = 1);

use Programster\Http\HttpCode;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Interfaces\RouteCollectorProxyInterface;


class ApiStatsController extends AbstractController
{
    public static function registerRoutes(RouteCollectorProxyInterface $group) : void
    {
        $group->get('/stats', [self::class, 'getStats'])->setName('api.stats');
        $group->get('/rate-limits', [self::class, 'getRateLimits'])->setName('api.rate-limits.get');
        $group->put('/rate-limits', [self::class, 'replaceRateLimits'])->setName('api.rate-limits.replace');
    }


    /**
     * Get the statistics, in the form the API returns them.
     *
     * @param string $emailTokenId - the token to get them for, or an empty string for everything.
     *
     * @return array
     */
    public static function presentStats(string $emailTokenId) : array
    {
        $hourly = [];

        foreach (Stats::getHourlyVolume($emailTokenId) as $volume)
        {
            /* @var $volume HourlyVolume */
            $hourly[] = [
                'hourStart' => $volume->getHourStart()->format(DATE_ATOM),
                'accepted' => $volume->getAccepted(),
                'queued' => $volume->getQueued(),
            ];
        }

        return ['activity' => Stats::getActivity($emailTokenId)->toArray(), 'hourly' => $hourly];
    }


    public function getStats(Request $request, Response $response) : Response
    {
        return self::json($response, self::presentStats(''));
    }


    public function getRateLimits(Request $request, Response $response) : Response
    {
        $limits = GlobalRateLimitRepository::load();
        return self::json($response, ['rateLimits' => ApiPresenter::presentRateLimits($limits)]);
    }


    public function replaceRateLimits(Request $request, Response $response) : Response
    {
        try
        {
            $limits = PolicyInput::readRateLimitsFromJson(self::getJsonBody($request), 'rateLimits');
        }
        catch (InvalidInputException $e)
        {
            throw new ApiException(HttpCode::BAD_REQUEST, ErrorCodeEnum::INVALID_INPUT, $e->getMessage());
        }

        GlobalRateLimitRepository::replace($limits);
        $context = ['limits' => $limits->describe()];
        ServiceFactory::getLogger(LogCategory::ADMIN)->notice('Global rate limits changed through the API', $context);
        return self::json($response, ['rateLimits' => ApiPresenter::presentRateLimits($limits)]);
    }
}
