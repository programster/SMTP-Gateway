<?php

/*
 * /api/v1/email-tokens: listing, creating, reading and revoking email tokens. Needs an API key.
 */

declare(strict_types = 1);

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Interfaces\RouteCollectorProxyInterface;


class ApiEmailTokensController extends AbstractController
{
    public static function registerRoutes(RouteCollectorProxyInterface $group) : void
    {
        $group->get('/email-tokens', [self::class, 'list'])->setName('api.email-tokens.list');
        $group->post('/email-tokens', [self::class, 'create'])->setName('api.email-tokens.create');
        $group->get('/email-tokens/{id}', [self::class, 'get'])->setName('api.email-tokens.get');
        $group->delete('/email-tokens/{id}', [self::class, 'revoke'])->setName('api.email-tokens.revoke');
        $group->get('/email-tokens/{id}/stats', [self::class, 'getStats'])->setName('api.email-tokens.stats');
    }


    public function list(Request $request, Response $response) : Response
    {
        $includeRevoked = self::getQueryParam($request, 'includeRevoked') === 'true';
        $emailTokens = [];

        foreach (EmailTokenRepository::loadAll($includeRevoked) as $emailToken)
        {
            $emailTokens[] = ApiPresenter::presentEmailToken($emailToken);
        }

        return self::json($response, ['emailTokens' => $emailTokens]);
    }


    public function create(Request $request, Response $response) : Response
    {
        $body = self::getJsonBody($request);

        try
        {
            $name = is_string($body['name'] ?? null) ? $body['name'] : '';
            $created = EmailTokenRepository::create($name, PolicyInput::readPolicyFromJson($body));
        }
        catch (InvalidInputException $e)
        {
            throw new ApiException(400, ErrorCodeEnum::INVALID_INPUT, $e->getMessage());
        }

        $apiKey = $request->getAttribute('api_key');

        ServiceFactory::getLogger(LogCategory::ADMIN)->info('Email token created', [
            'email_token_id' => $created->getEmailToken()->getId(),
            'name' => $created->getEmailToken()->getName(),
            'by_api_key' => ($apiKey instanceof ApiKey) ? $apiKey->getId() : '',
        ]);

        $output = [
            'emailToken' => ApiPresenter::presentEmailToken($created->getEmailToken()),
            'token' => $created->getToken()->getPlaintext(),
        ];

        return self::json($response, $output, 201);
    }


    public function get(Request $request, Response $response, array $args) : Response
    {
        $emailToken = EmailTokenRepository::load($args['id']);
        return self::json($response, ['emailToken' => ApiPresenter::presentEmailToken($emailToken)]);
    }


    public function revoke(Request $request, Response $response, array $args) : Response
    {
        $emailToken = EmailTokenRepository::load($args['id']);
        $cancelled = EmailTokenRepository::revoke($emailToken);
        $apiKey = $request->getAttribute('api_key');

        ServiceFactory::getLogger(LogCategory::ADMIN)->notice('Email token revoked', [
            'email_token_id' => $emailToken->getId(),
            'cancelled_queued_emails' => $cancelled,
            'by_api_key' => ($apiKey instanceof ApiKey) ? $apiKey->getId() : '',
        ]);

        $output = [
            'emailToken' => ApiPresenter::presentEmailToken(EmailTokenRepository::load($emailToken->getId())),
            'cancelledQueuedEmails' => $cancelled,
        ];

        return self::json($response, $output);
    }


    public function getStats(Request $request, Response $response, array $args) : Response
    {
        $emailToken = EmailTokenRepository::load($args['id']);
        return self::json($response, ApiStatsController::presentStats($emailToken->getId()));
    }
}
