<?php

/*
 * Turns any exception that escapes a route into a response: JSON (in the shape
 * { "error": { "message": "...", "code": ... } }) for the API, the webhooks and the web interface's JSON
 * endpoints, and an error page for everything else. Unexpected errors are logged, and their details are only
 * shown in development.
 */

declare(strict_types = 1);

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpException;


final class ErrorHandler
{
    public function __construct()
    {
    }


    public function __invoke(
        ServerRequestInterface $request,
        Throwable $exception,
        bool $displayErrorDetails,
        bool $logErrors,
        bool $logErrorDetails
    ) : ResponseInterface
    {
        $apiException = self::convertToApiException($exception, $displayErrorDetails);

        if ($apiException->getHttpStatus() >= 500)
        {
            ServiceFactory::getLogger(LogCategory::SYSTEM)->error('Unhandled error: ' . $exception->getMessage(), [
                'path' => $request->getUri()->getPath(),
                'method' => $request->getMethod(),
                'exception' => get_class($exception),
                'file' => $exception->getFile() . ':' . $exception->getLine(),
                'trace' => $exception->getTraceAsString(),
            ]);
        }

        $response = new \Slim\Psr7\Response($apiException->getHttpStatus());

        if (self::wantsJson($request))
        {
            $response->getBody()->write(json_encode($apiException->toArray()));
            $response = $response->withHeader('Content-Type', 'application/json');
        }
        else
        {
            $page = new ViewErrorPage($apiException->getHttpStatus(), $apiException->getMessage());
            $response->getBody()->write((string)new ViewTemplateHtmlShell('Error', $page));
            $response = $response->withHeader('Content-Type', 'text/html; charset=utf-8');
        }

        return $response;
    }


    private static function convertToApiException(Throwable $exception, bool $displayErrorDetails) : ApiException
    {
        if ($exception instanceof ApiException)
        {
            $result = $exception;
        }
        elseif ($exception instanceof HttpException)
        {
            $code = ($exception->getCode() === 404) ? ErrorCodeEnum::NOT_FOUND : ErrorCodeEnum::UNKNOWN;
            $result = new ApiException($exception->getCode(), $code, $exception->getTitle());
        }
        elseif ($exception instanceof NotFoundException)
        {
            $result = new ApiException(404, ErrorCodeEnum::NOT_FOUND, $exception->getMessage());
        }
        elseif ($exception instanceof AccountStateException)
        {
            $result = new ApiException(409, $exception->getProblem()->getErrorCode(), $exception->getMessage());
        }
        elseif ($exception instanceof ConflictException)
        {
            $result = new ApiException(409, ErrorCodeEnum::CONFLICT, $exception->getMessage());
        }
        elseif ($exception instanceof InvalidInputException)
        {
            $result = new ApiException(400, ErrorCodeEnum::INVALID_INPUT, $exception->getMessage());
        }
        elseif ($exception instanceof RequestRejectedException)
        {
            $result = ApiException::createFromRejection($exception);
        }
        else
        {
            $message = $displayErrorDetails ? $exception->getMessage() : 'Whoops! something went wrong';
            $result = new ApiException(500, ErrorCodeEnum::UNKNOWN, $message);
        }

        return $result;
    }


    private static function wantsJson(ServerRequestInterface $request) : bool
    {
        $path = $request->getUri()->getPath();

        return (
               str_starts_with($path, '/api/')
            || str_starts_with($path, '/webhooks/')
            || str_contains($request->getHeaderLine('Accept'), 'application/json')
            || str_contains($request->getHeaderLine('Content-Type'), 'application/json')
        );
    }
}
