<?php

/*
 * For the web interface: starts the session, sends anyone not logged in to the login page (except on the login
 * pages themselves, whose route names start with "public."), and checks the CSRF token on every request that
 * changes something. The token is sent as the "csrf_token" form field, or the X-CSRF-Token header for JSON.
 *
 * When two-factor authentication is required (on the Settings page), an account that hasn't set one up yet is sent to
 * "My account" from every page, except the ones it needs to set one up (and log out). An account that was given a
 * password by an administrator, who asked for it to be changed, is sent there first, to choose its own.
 */

declare(strict_types = 1);

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Routing\RouteContext;


class MiddlewareWebSession implements \Psr\Http\Server\MiddlewareInterface
{
    // The routes an account can still use while it must choose its own password.
    private const array PASSWORD_CHANGE_ROUTES = ['account', 'account.password', 'logout'];

    // The routes an account can still use while it must set up a second factor. Changing its password is one, as an
    // account may have to choose its own password first (see PASSWORD_CHANGE_ROUTES).
    private const array SECOND_FACTOR_SETUP_ROUTES = [
        'account',
        'account.password',
        'account.totp',
        'account.totp.enable',
        'account.security-keys.options',
        'account.security-keys.register',
        'logout',
    ];


    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler) : ResponseInterface
    {
        WebSession::start(ClientIpResolver::isHttps($request));
        $route = RouteContext::fromRequest($request)->getRoute();
        $routeName = ($route === null) ? '' : (string)$route->getName();
        $isPublic = str_starts_with($routeName, 'public.');
        $isSafeMethod = in_array($request->getMethod(), ['GET', 'HEAD', 'OPTIONS'], true);

        $body = $request->getParsedBody();
        $formToken = (is_array($body) && is_string($body['csrf_token'] ?? null)) ? $body['csrf_token'] : '';
        $token = ($formToken !== '') ? $formToken : $request->getHeaderLine('X-CSRF-Token');

        if ($isSafeMethod === false && WebSession::isValidCsrfToken($token) === false)
        {
            $msg = 'Your session has expired, or the form was sent from somewhere else. Please go back and try again.';
            throw new ApiException(403, ErrorCodeEnum::INVALID_CREDENTIALS, $msg);
        }

        if ($isPublic === false && WebSession::isLoggedIn() === false)
        {
            $response = (new \Slim\Psr7\Response(303))->withHeader('Location', '/login');
        }
        elseif ($isPublic === false && self::mustChangePassword($routeName))
        {
            if (str_contains($request->getHeaderLine('Accept'), 'application/json'))
            {
                $msg = 'Your password has to be changed first, under "My account".';
                throw new ApiException(403, ErrorCodeEnum::PASSWORD_CHANGE_REQUIRED, $msg);
            }

            $response = (new \Slim\Psr7\Response(303))->withHeader('Location', '/account');
        }
        elseif ($isPublic === false && self::mustSetUpSecondFactor($routeName))
        {
            if (str_contains($request->getHeaderLine('Accept'), 'application/json'))
            {
                $msg = 'Two-factor authentication is required. Set it up under "My account" first.';
                throw new ApiException(403, ErrorCodeEnum::MFA_SETUP_REQUIRED, $msg);
            }

            $response = (new \Slim\Psr7\Response(303))->withHeader('Location', '/account');
        }
        else
        {
            $response = $handler->handle($request);
        }

        // Pages that show secrets or private data mustn't be cached.
        return $response->withHeader('Cache-Control', 'no-store');
    }


    /**
     * Whether the logged-in account has to choose its own password before it can use this route.
     *
     * @param string $routeName - the route's name.
     *
     * @return bool
     *
     * @throws NotFoundException - if the account has been deleted (which logs it out).
     */
    private static function mustChangePassword(string $routeName) : bool
    {
        return (
               in_array($routeName, self::PASSWORD_CHANGE_ROUTES, true) === false
            && WebSession::getUser()->mustChangePassword()
        );
    }


    /**
     * Whether the logged-in account has to set up a second factor before it can use this route. A session that
     * logged in with Google, when the Settings page says Google "always" skips the second factor, never has to.
     *
     * @param string $routeName - the route's name.
     *
     * @return bool
     *
     * @throws NotFoundException - if the account has been deleted (which logs it out).
     */
    private static function mustSetUpSecondFactor(string $routeName) : bool
    {
        return (
               Settings::get()->isMfaRequired()
            && in_array($routeName, self::SECOND_FACTOR_SETUP_ROUTES, true) === false
            && WebSession::hasBypassedSecondFactor() === false
            && AdminUserRepository::hasSecondFactor(WebSession::getUser()) === false
        );
    }
}
