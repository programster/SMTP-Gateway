<?php

/*
 * Creating and revoking the API keys for the management API (/api/v1), which are used as bearer tokens.
 */

declare(strict_types = 1);

use Programster\Http\HttpCode;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Interfaces\RouteCollectorProxyInterface;


class ApiKeysController extends AbstractController
{
    public static function registerRoutes(RouteCollectorProxyInterface $group) : void
    {
        $group->get('/api-keys', [self::class, 'list'])->setName('api-keys');
        $group->post('/api-keys', [self::class, 'create'])->setName('api-keys.create');
        $group->post('/api-keys/{id}/revoke', [self::class, 'revoke'])->setName('api-keys.revoke');
    }


    public function list(Request $request, Response $response) : Response
    {
        // The first row of the allowed IP addresses (see ViewValueRows) is the address being used now.
        $defaults = ['name' => '', 'allowed_ips_0' => (string)ClientIpResolver::resolve($request)];
        $isShowingRevoked = (self::getQueryParam($request, 'revoked') === '1');
        $apiKeys = ApiKeyRepository::loadAll();
        $networks = ApiKeyRepository::loadAllowedNetworkStrings();
        $page = new ViewApiKeysPage($apiKeys, $networks, $isShowingRevoked, $defaults, '');
        return self::renderPage($response, 'API keys', $page);
    }


    public function create(Request $request, Response $response) : Response
    {
        $name = self::getFormField($request, 'name');

        $getField = function (string $field) use ($request) : string {
            return self::getFormField($request, $field);
        };

        try
        {
            $created = ApiKeyRepository::create($name, PolicyInput::readIpAllowListFromForm($getField));

            ServiceFactory::getLogger(LogCategory::ADMIN)->info('API key created', [
                'api_key_id' => $created->getApiKey()->getId(),
                'name' => $created->getApiKey()->getName(),
                'by_user_id' => WebSession::getUser()->getId(),
            ]);

            $page = new ViewApiKeyCreatedPage($created, self::getSiteUrl($request));
            $result = self::renderPage($response, 'API key created', $page);
        }
        catch (InvalidInputException $e)
        {
            $values = ['name' => $name];

            foreach (PolicyInput::readValueRowsFromForm($getField, 'allowed_ips') as $row => $value)
            {
                $values['allowed_ips_' . ($row - 1)] = $value;
            }

            $apiKeys = ApiKeyRepository::loadAll();
            $networks = ApiKeyRepository::loadAllowedNetworkStrings();
            $page = new ViewApiKeysPage($apiKeys, $networks, false, $values, $e->getMessage());
            $result = self::renderPage($response, 'API keys', $page, HttpCode::BAD_REQUEST);
        }

        return $result;
    }


    public function revoke(Request $request, Response $response, array $args) : Response
    {
        $apiKey = ApiKeyRepository::load($args['id']);
        ApiKeyRepository::revoke($apiKey);

        ServiceFactory::getLogger(LogCategory::ADMIN)->notice('API key revoked', [
            'api_key_id' => $apiKey->getId(),
            'by_user_id' => WebSession::getUser()->getId(),
        ]);

        WebSession::flash("The API key \"{$apiKey->getName()}\" was revoked.");
        return self::redirect($response, '/api-keys');
    }
}
