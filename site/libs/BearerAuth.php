<?php

/*
 * Reads the credential from an "Authorization: Bearer <token>" header, which the management API (with an API key)
 * and the send endpoint (with an email token) both take. The API doesn't take HTTP Basic auth (only the webhooks
 * from providers that don't sign theirs do, see WebhookBasicAuth).
 */

declare(strict_types = 1);

use Programster\Http\HttpCode;
use Psr\Http\Message\ServerRequestInterface;


final class BearerAuth
{
    /**
     * Read the token from a request's Authorization header.
     *
     * @param ServerRequestInterface $request - the request.
     *
     * @param TokenKind $kind - the kind of token the endpoint takes, to say which is needed if there's none.
     *
     * @return string - the token, as given, to be checked by whatever takes it.
     *
     * @throws ApiException - a 401, if there's no "Bearer" Authorization header.
     */
    public static function readToken(ServerRequestInterface $request, TokenKind $kind) : string
    {
        $header = $request->getHeaderLine('Authorization');

        if (preg_match('/^Bearer\s+(\S+)$/i', $header, $matches) !== 1)
        {
            $label = $kind->getLabel();

            $msg =
                "An {$label} is required, as \"Authorization: Bearer <{$label}>\". They're created in the web"
                . ' interface.';

            throw new ApiException(HttpCode::UNAUTHORIZED, ErrorCodeEnum::MISSING_CREDENTIALS, $msg);
        }

        return $matches[1];
    }
}
