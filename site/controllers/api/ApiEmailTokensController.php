<?php

/*
 * /api/v1/email-tokens: listing, creating, reading, changing (PUT to replace, PATCH to change only what's given) and
 * revoking email tokens. Needs an API key.
 */

declare(strict_types = 1);

use Programster\Http\HttpCode;
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
        $group->put('/email-tokens/{id}', [self::class, 'replace'])->setName('api.email-tokens.replace');
        $group->patch('/email-tokens/{id}', [self::class, 'patch'])->setName('api.email-tokens.patch');
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
            throw new ApiException(HttpCode::BAD_REQUEST, ErrorCodeEnum::INVALID_INPUT, $e->getMessage());
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

        return self::json($response, $output, HttpCode::CREATED);
    }


    public function get(Request $request, Response $response, array $args) : Response
    {
        $emailToken = EmailTokenRepository::load($args['id']);
        return self::json($response, ['emailToken' => ApiPresenter::presentEmailToken($emailToken)]);
    }


    /**
     * Replace a token's name and policy with the ones given, which must be complete (see
     * PolicyInput::readReplacementPolicyFromJson()). Its ID and secret stay the same.
     *
     * @throws NotFoundException - if there's no such token.
     *
     * @throws InvalidInputException - if the body is incomplete or invalid.
     *
     * @throws ConflictException - if the token has been revoked.
     */
    public function replace(Request $request, Response $response, array $args) : Response
    {
        $emailToken = EmailTokenRepository::load($args['id']);
        $body = self::getJsonBody($request);
        $policy = PolicyInput::readReplacementPolicyFromJson($body);
        return self::saveChange($request, $response, $emailToken, self::readName($body), $policy);
    }


    /**
     * Change only the parts of a token that are given (see PolicyInput::readPolicyPatchFromJson()), keeping the rest.
     * Its ID and secret stay the same.
     *
     * @throws NotFoundException - if there's no such token.
     *
     * @throws InvalidInputException - if the body is invalid.
     *
     * @throws ConflictException - if the token has been revoked.
     */
    public function patch(Request $request, Response $response, array $args) : Response
    {
        $emailToken = EmailTokenRepository::load($args['id']);
        $body = self::getJsonBody($request);
        $name = array_key_exists('name', $body) ? self::readName($body) : $emailToken->getName();
        $policy = PolicyInput::readPolicyPatchFromJson($body, EmailTokenRepository::loadPolicy($emailToken));
        return self::saveChange($request, $response, $emailToken, $name, $policy);
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


    /**
     * Save a token's new name and policy, and respond with the token as it is now.
     *
     * @param Request $request - the request, for the API key that made the change.
     *
     * @param Response $response - the response.
     *
     * @param EmailToken $emailToken - the token.
     *
     * @param string $name - its new name, which may be the one it has.
     *
     * @param EmailTokenPolicy $policy - its new policy.
     *
     * @return Response
     *
     * @throws InvalidInputException - if the name is empty or too long.
     *
     * @throws ConflictException - if the token has been revoked.
     */
    private static function saveChange(
        Request $request,
        Response $response,
        EmailToken $emailToken,
        string $name,
        EmailTokenPolicy $policy
    ) : Response
    {
        EmailTokenRepository::update($emailToken, $name, $policy);
        $apiKey = $request->getAttribute('api_key');
        $changed = EmailTokenRepository::load((string)$emailToken->getId());

        ServiceFactory::getLogger(LogCategory::ADMIN)->info('Email token changed', [
            'email_token_id' => $changed->getId(),
            'name' => $changed->getName(),
            'by_api_key' => ($apiKey instanceof ApiKey) ? $apiKey->getId() : '',
        ]);

        return self::json($response, ['emailToken' => ApiPresenter::presentEmailToken($changed)]);
    }


    /**
     * @throws InvalidInputException - if "name" isn't a string.
     */
    private static function readName(array $body) : string
    {
        $name = $body['name'] ?? null;

        if (!is_string($name))
        {
            throw new InvalidInputException('"name" must be a string, from 1 to 255 characters long.');
        }

        return $name;
    }
}
