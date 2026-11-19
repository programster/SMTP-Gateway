<?php

/*
 * For the management API: requires a valid, unrevoked API key, as a bearer token ("Authorization: Bearer ak_..."),
 * used from one of the IP addresses the key is allowed to be used from.
 */

declare(strict_types = 1);

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;


class MiddlewareApiKey implements \Psr\Http\Server\MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler) : ResponseInterface
    {
        $given = BearerAuth::readToken($request, TokenKind::API_KEY);

        try
        {
            $apiKey = ApiKeyRepository::findByToken(Token::createFromString($given, TokenKind::API_KEY));
        }
        catch (InvalidTokenException $e)
        {
            // e.g. an email token given instead, which it says.
            throw new ApiException(401, ErrorCodeEnum::INVALID_CREDENTIALS, $e->getMessage());
        }
        catch (NotFoundException)
        {
            throw new ApiException(401, ErrorCodeEnum::INVALID_CREDENTIALS, 'Invalid API key.');
        }

        if ($apiKey->isRevoked())
        {
            throw new ApiException(401, ErrorCodeEnum::REVOKED_CREDENTIALS, 'This API key has been revoked.');
        }

        $clientIp = ClientIpResolver::resolve($request);

        if (ApiKeyRepository::loadAllowedNetworks($apiKey)->allows($clientIp) === false)
        {
            $msg = "This API key may not be used from {$clientIp}.";
            throw new ApiException(403, ErrorCodeEnum::IP_NOT_ALLOWED, $msg);
        }

        ApiKeyRepository::markUsed($apiKey, $clientIp);
        return $handler->handle($request->withAttribute('api_key', $apiKey));
    }
}
