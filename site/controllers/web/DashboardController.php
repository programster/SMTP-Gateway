<?php

/*
 * The dashboard: how much email is coming through, from which tokens, and what's happening to it, with a warning at
 * the top if the service seems not to be seeing people's real IP addresses (see ProxyWarning).
 */

declare(strict_types = 1);

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Interfaces\RouteCollectorProxyInterface;


class DashboardController extends AbstractController
{
    public static function registerRoutes(RouteCollectorProxyInterface $group) : void
    {
        $group->get('/', [self::class, 'show'])->setName('dashboard');
        $group->post('/proxy-warning/dismiss', [self::class, 'dismissProxyWarning'])->setName('proxy-warning.dismiss');
    }


    public function show(Request $request, Response $response) : Response
    {
        $activities = [];

        foreach (EmailTokenRepository::loadAll(false) as $emailToken)
        {
            /* @var $emailToken EmailToken */
            $activities[] = new EmailTokenActivity($emailToken, Stats::getActivity($emailToken->getId()));
        }

        $activity = new EmailTokenActivityCollection(...$activities);

        $page = new ViewDashboardPage(
            Stats::getActivity(),
            Stats::getHourlyVolume(),
            $activity,
            GlobalRateLimitRepository::load(),
            self::loadProvidersOverview(),
            AdminUserRepository::hasSecondFactor(WebSession::getUser()),
            self::loadUsage(UsagePeriod::tryFrom(self::getQueryParam($request, ViewMostUsedTokensCard::PERIOD_PARAM))),
            ProxyWarning::createForRequest($request)
        );

        return self::renderPage($response, 'Dashboard', $page);
    }


    /**
     * Stop showing the warning about proxies in this browser (with a cookie), for a service that really is reached
     * directly, inside a home or office network. It lasts 400 days, the longest a browser keeps a cookie.
     */
    public function dismissProxyWarning(Request $request, Response $response) : Response
    {
        $secure = ClientIpResolver::isHttps($request) ? '; Secure' : '';
        $maxAge = 400 * 24 * 60 * 60;
        $cookie = ProxyWarning::DISMISSED_COOKIE . "=1; Max-Age={$maxAge}; Path=/; HttpOnly; SameSite=Lax{$secure}";
        WebSession::flash('The warning about proxies won\'t be shown in this browser again.');
        return self::redirect($response, '/')->withHeader('Set-Cookie', $cookie);
    }


    /**
     * Load who and what has been using the service, for the dashboard's widgets.
     *
     * @param UsagePeriod|null $period - the period to count the most used email tokens over, or null for the default
     * (the last week), e.g. when the link it came from was mistyped.
     *
     * @return DashboardUsage
     */
    private static function loadUsage(?UsagePeriod $period) : DashboardUsage
    {
        $limit = 5;
        $chosen = $period ?? UsagePeriod::WEEK;
        $logins = [];
        $apiKeys = [];
        $emailTokens = [];

        foreach (AdminUserRepository::loadRecentLogins($limit) as $user)
        {
            $logins[] = RecentUse::createForUser($user);
        }

        foreach (ApiKeyRepository::loadRecentlyUsed($limit) as $apiKey)
        {
            $apiKeys[] = RecentUse::createForApiKey($apiKey);
        }

        foreach (EmailTokenRepository::loadRecentlyUsed($limit) as $emailToken)
        {
            $emailTokens[] = RecentUse::createForEmailToken($emailToken);
        }

        try
        {
            $recordDays = Settings::get()->getRetention()->getEmailRetention()->getRecordDays();
        }
        catch (ConfigurationException)
        {
            // While the retention settings are invalid, nothing is deleted, so every record is still there.
            $recordDays = PHP_INT_MAX;
        }

        return new DashboardUsage(
            new RecentUseCollection(...$logins),
            new RecentUseCollection(...$apiKeys),
            new RecentUseCollection(...$emailTokens),
            Stats::loadMostUsedEmailTokens($chosen->getWindow(), $limit),
            $chosen,
            $recordDays
        );
    }


    /**
     * Get what the dashboard says about the providers as they are now: which are sending, or why none can.
     * @return ProvidersOverview
     */
    private static function loadProvidersOverview() : ProvidersOverview
    {
        try
        {
            $overview = ProvidersOverview::createUsingConfig(ProviderRegistry::getConfig());
        }
        catch (ProvidersNotConfiguredException)
        {
            $overview = ProvidersOverview::createNotConfigured();
        }
        catch (ConfigurationException $e)
        {
            $overview = ProvidersOverview::createInvalid($e->getMessage());
        }

        return $overview;
    }
}
