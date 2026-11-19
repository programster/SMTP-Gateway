<?php

/*
 * The webhook a provider calls to report what happened to each email (delivered, bounced, opened, etc), at
 * /webhooks/<the provider's name in settings.yaml>, e.g. /webhooks/sendgrid-main. A provider's webhook only exists
 * once it's set up (e.g. a SendGrid provider needs its webhook_public_key), as unverifiable events mustn't be
 * recorded.
 */

declare(strict_types = 1);

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Interfaces\RouteCollectorProxyInterface;


class WebhookController extends AbstractController
{
    public static function registerRoutes(RouteCollectorProxyInterface $group) : void
    {
        $group->post('/webhooks/{provider}', [self::class, 'receive'])->setName('webhooks');
    }


    public function receive(Request $request, Response $response, array $args) : Response
    {
        try
        {
            $provider = ProviderRegistry::getProvider($args['provider']);
        }
        catch (NotFoundException | ConfigurationException)
        {
            throw new ApiException(404, ErrorCodeEnum::NOT_FOUND, 'There is no such webhook.');
        }

        if (!($provider instanceof ProviderWebhookInterface) || $provider->isWebhookEnabled() === false)
        {
            throw new ApiException(404, ErrorCodeEnum::NOT_FOUND, 'There is no such webhook.');
        }

        try
        {
            $events = $provider->parseWebhook((string)$request->getBody(), $request);
        }
        catch (RequestRejectedException $e)
        {
            ServiceFactory::getLogger(LogCategory::EMAIL)->warning('Webhook request with an invalid signature', [
                'provider' => $args['provider'],
                'client_ip' => (string)ClientIpResolver::resolve($request),
            ]);

            throw new ApiException(401, ErrorCodeEnum::INVALID_CREDENTIALS, $e->getMessage());
        }

        $recorded = EventRecorder::record($provider->getName(), $events);
        return self::json($response, ['received' => count($events), 'recorded' => $recorded]);
    }
}
