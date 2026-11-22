<?php

/*
 * The base of every controller. Routes point at controller methods, e.g. [EmailTokensController::class, 'list'], which
 * Slim calls on a new instance with ($request, $response, $args).
 */

declare(strict_types = 1);

use Programster\Http\HttpCode;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Interfaces\RouteCollectorProxyInterface;


abstract class AbstractController
{
    /**
     * Register this controller's routes.
     *
     * @param RouteCollectorProxyInterface $group - the app, or the route group, to add them to.
     *
     * @return void
     */
    abstract public static function registerRoutes(RouteCollectorProxyInterface $group) : void;


    /**
     * Respond with a full page.
     *
     * @param ResponseInterface $response - the response to write to.
     *
     * @param string $title - the page's title.
     *
     * @param \Programster\AbstractView\AbstractView $page - the page's content.
     *
     * @param HttpCode $status - the HTTP status code.
     *
     * @return ResponseInterface
     */
    protected static function renderPage(
        ResponseInterface $response,
        string $title,
        \Programster\AbstractView\AbstractView $page,
        HttpCode $status = HttpCode::OK
    ) : ResponseInterface
    {
        $response->getBody()->write((string)new ViewTemplateHtmlShell($title, $page));
        return $response->withStatus($status->value)->withHeader('Content-Type', 'text/html; charset=utf-8');
    }


    /**
     * Redirect to another page (after a form was posted, so refreshing doesn't post it again).
     *
     * @param ResponseInterface $response - the response.
     *
     * @param string $path - where to go, e.g. "/email-tokens".
     *
     * @return ResponseInterface
     */
    protected static function redirect(ResponseInterface $response, string $path) : ResponseInterface
    {
        return $response->withStatus(HttpCode::SEE_OTHER->value)->withHeader('Location', $path);
    }


    /**
     * Respond with JSON.
     *
     * @param ResponseInterface $response - the response.
     *
     * @param mixed $data - what to encode.
     *
     * @param HttpCode $status - the HTTP status code.
     *
     * @return ResponseInterface
     */
    protected static function json(
        ResponseInterface $response,
        mixed $data,
        HttpCode $status = HttpCode::OK
    ) : ResponseInterface
    {
        $response->getBody()->write(json_encode($data, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        return $response->withStatus($status->value)->withHeader('Content-Type', 'application/json');
    }


    /**
     * Respond with an email's content, as a .eml file to download.
     *
     * @param ResponseInterface $response - the response.
     *
     * @param EmailMessage $message - the email.
     *
     * @return ResponseInterface
     *
     * @throws NotFoundException - if its content isn't kept (see the retention settings), or has been deleted.
     */
    protected static function downloadEmail(ResponseInterface $response, EmailMessage $message) : ResponseInterface
    {
        $response->getBody()->write(self::loadRawEmail($message));

        return $response
            ->withHeader('Content-Type', 'message/rfc822')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $message->getId() . '.eml"');
    }


    /**
     * Get an email's content, in its raw MIME form, for downloading or showing.
     *
     * @param EmailMessage $message - the email.
     *
     * @return string
     *
     * @throws NotFoundException - if it's one of the service's own emails (which can hold a password reset link,
     * even while it's queued), or its content wasn't kept.
     */
    protected static function loadRawEmail(EmailMessage $message) : string
    {
        $raw = base64_decode((string)$message->getRawMessageBase64(), true);

        if ($message->isFromService())
        {
            $msg = 'The service\'s own emails can\'t be downloaded, as they can hold password reset links.';
            throw new NotFoundException($msg);
        }
        elseif ($raw === false || $raw === '')
        {
            $msg =
                "This email's content isn't available: email content is "
                . Settings::get()->describeContentRetention() . ' (see the Settings page).';

            throw new NotFoundException($msg);
        }

        return $raw;
    }


    /**
     * Check whether the request asked for JSON (with its Accept header), as a form posted by JavaScript does, so it
     * gets JSON back rather than a page.
     *
     * @param ServerRequestInterface $request - the request.
     *
     * @return bool
     */
    protected static function wantsJson(ServerRequestInterface $request) : bool
    {
        return str_contains($request->getHeaderLine('Accept'), 'application/json');
    }


    /**
     * Get a string field from a posted form.
     *
     * @param ServerRequestInterface $request - the request.
     *
     * @param string $name - the field's name.
     *
     * @return string - its value, or an empty string if it wasn't sent (or wasn't a string).
     */
    protected static function getFormField(ServerRequestInterface $request, string $name) : string
    {
        $body = $request->getParsedBody();
        $value = is_array($body) ? ($body[$name] ?? '') : '';
        return is_string($value) ? $value : '';
    }


    /**
     * Get a string from the query string.
     *
     * @param ServerRequestInterface $request - the request.
     *
     * @param string $name - the parameter's name.
     *
     * @return string - its value, or an empty string if it wasn't sent.
     */
    protected static function getQueryParam(ServerRequestInterface $request, string $name) : string
    {
        $value = $request->getQueryParams()[$name] ?? '';
        return is_string($value) ? trim($value) : '';
    }


    /**
     * Get the decoded JSON body of a request.
     *
     * @param ServerRequestInterface $request - the request.
     *
     * @return array
     *
     * @throws ApiException - if the body isn't a JSON object.
     */
    protected static function getJsonBody(ServerRequestInterface $request) : array
    {
        $body = json_decode((string)$request->getBody(), true);

        if (!is_array($body) || (count($body) > 0 && array_is_list($body)))
        {
            $message = 'The request body must be a JSON object.';
            throw new ApiException(HttpCode::BAD_REQUEST, ErrorCodeEnum::INVALID_JSON, $message);
        }

        return $body;
    }


    /**
     * Get the hostname the site is being used at, for WebAuthn.
     *
     * @param ServerRequestInterface $request - the request.
     *
     * @return string
     */
    protected static function getHostname(ServerRequestInterface $request) : string
    {
        return $request->getUri()->getHost();
    }


    /**
     * Get the address the site is reached at, for examples shown on a page (e.g. a curl command): PUBLIC_URL, or, if
     * it isn't set, the address the page was loaded from.
     *
     * @param ServerRequestInterface $request - the request for the page.
     *
     * @return string - e.g. "https://smtp.example.org", with no trailing slash.
     */
    protected static function getSiteUrl(ServerRequestInterface $request) : string
    {
        $config = Config::get();
        $uri = $request->getUri();
        $scheme = ClientIpResolver::isHttps($request) ? 'https' : 'http';
        $port = ($uri->getPort() === null) ? '' : ':' . $uri->getPort();
        $loadedFrom = "{$scheme}://{$uri->getHost()}{$port}";
        return $config->hasPublicUrl() ? $config->getPublicUrl() : $loadedFrom;
    }


    /**
     * Give an account with a second factor a new set of backup codes if it has no unused ones left (or never had
     * any), to show it once (see AccountController::showBackupCodes()). Without them, losing its second factor would
     * lock it out.
     *
     * @param AdminUser $user - the account.
     *
     * @param string $otherwisePath - where to go next if it didn't need new ones.
     *
     * @return string - where to go next: the page showing the new codes, or $otherwisePath.
     */
    protected static function issueBackupCodesIfNone(AdminUser $user, string $otherwisePath) : string
    {
        $path = $otherwisePath;

        if (
               AdminUserRepository::hasSecondFactor($user)
            && BackupCodeService::loadStatus($user)->getNumUnused() === 0
        )
        {
            WebSession::setNewBackupCodes(...BackupCodeService::replace($user));
            $logger = ServiceFactory::getLogger(LogCategory::ACCESS);
            $logger->notice('Backup codes generated, as there were none left', ['user_id' => $user->getId()]);
            $path = '/account/backup-codes';
        }

        return $path;
    }
}
