<?php

declare(strict_types = 1);

use Slim\Interfaces\RouteCollectorProxyInterface;

require_once(__DIR__ . '/../bootstrap.php');


$app = Slim\Factory\AppFactory::create();

$app->addBodyParsingMiddleware();
$app->addRoutingMiddleware();
$app->addMiddleware(new MiddlewareSecurityHeaders());
$app->addMiddleware(new MiddlewareTrailingSlash()); // this must be last (which means it executes first).

// Register the error middleware. This must be registered last so that it gets executed first. Every error, for the
// API and the web interface alike, goes through ErrorHandler.
$errorMiddleware = $app->addErrorMiddleware(
    displayErrorDetails: Config::get()->isDev(),
    logErrors: false,
    logErrorDetails: false
);

$errorMiddleware->setDefaultErrorHandler(new ErrorHandler());

// The web interface, which needs a logged-in session (apart from the login pages).
$registerWebRoutes = function (RouteCollectorProxyInterface $group) : void {
    AccountController::registerRoutes($group);
    AdminController::registerRoutes($group);
    AuthController::registerRoutes($group);
    DashboardController::registerRoutes($group);
    DocumentationController::registerRoutes($group);
    EmailTokensController::registerRoutes($group);
    MessagesController::registerRoutes($group);
    ProvidersController::registerRoutes($group);
    RateLimitsController::registerRoutes($group);
    SettingsController::registerRoutes($group);
    ApiKeysController::registerRoutes($group);
};

$app->group('', $registerWebRoutes)->add(new MiddlewareWebSession());

// The management API, which needs a bearer token.
$registerApiRoutes = function (RouteCollectorProxyInterface $group) : void {
    ApiEmailTokensController::registerRoutes($group);
    ApiUsersController::registerRoutes($group);
    ApiMessagesController::registerRoutes($group);
    ApiProvidersController::registerRoutes($group);
    ApiStatsController::registerRoutes($group);
};

$app->group('/api/v1', $registerApiRoutes)->add(new MiddlewareApiKey());

// Public routes: the API documentation, the send endpoint (which checks its own email token credentials), and the
// provider's webhook (which checks its own signature).
ApiSendController::registerRoutes($app);
DocsController::registerRoutes($app);
WebhookController::registerRoutes($app);

$app->run();
