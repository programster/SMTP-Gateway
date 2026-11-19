<?php

/*
 * Creating, viewing and revoking the email tokens that other services send email with.
 */

declare(strict_types = 1);

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Interfaces\RouteCollectorProxyInterface;


class EmailTokensController extends AbstractController
{
    public static function registerRoutes(RouteCollectorProxyInterface $group) : void
    {
        $group->get('/email-tokens', [self::class, 'list'])->setName('email-tokens');
        $group->get('/email-tokens/new', [self::class, 'showNew'])->setName('email-tokens.new');
        $group->post('/email-tokens', [self::class, 'create'])->setName('email-tokens.create');
        $group->get('/email-tokens/{id}', [self::class, 'show'])->setName('email-tokens.show');
        $group->get('/email-tokens/{id}/sender-checks', [self::class, 'checkSenders'])
            ->setName('email-tokens.senderChecks');
        $group->post('/email-tokens/{id}/revoke', [self::class, 'revoke'])->setName('email-tokens.revoke');
    }


    public function list(Request $request, Response $response) : Response
    {
        return self::renderPage($response, 'Email tokens', new ViewEmailTokensPage(EmailTokenRepository::loadAll()));
    }


    public function showNew(Request $request, Response $response) : Response
    {
        // The first row of the IP addresses (see ViewValueRows): the address being used now. The address lists start
        // empty, which allows anyone.
        $defaults = ['allowed_ips_0' => (string)ClientIpResolver::resolve($request)];

        $page = new ViewNewEmailTokenPage($defaults, '', self::getSiteUrl($request));
        return self::renderPage($response, 'New email token', $page);
    }


    public function create(Request $request, Response $response) : Response
    {
        $getField = function (string $name) use ($request) : string {
            return self::getFormField($request, $name);
        };

        try
        {
            $policy = PolicyInput::readPolicyFromForm($getField);
            $created = EmailTokenRepository::create(self::getFormField($request, 'name'), $policy);

            ServiceFactory::getLogger(LogCategory::ADMIN)->info('Email token created', [
                'email_token_id' => $created->getEmailToken()->getId(),
                'name' => $created->getEmailToken()->getName(),
                'by_user_id' => WebSession::getUser()->getId(),
            ]);

            $smtpHostname = Config::get()->getSmtpServerHostname();
            $page = new ViewEmailTokenCreatedPage($created, $smtpHostname, self::getSiteUrl($request));
            $result = self::renderPage($response, 'Email token created', $page);
        }
        catch (InvalidInputException $e)
        {
            $body = $request->getParsedBody();
            $values = is_array($body) ? array_filter($body, 'is_string') : [];
            $page = new ViewNewEmailTokenPage($values, $e->getMessage(), self::getSiteUrl($request));
            $result = self::renderPage($response, 'New email token', $page, 400);
        }

        return $result;
    }


    public function show(Request $request, Response $response, array $args) : Response
    {
        $emailToken = EmailTokenRepository::load($args['id']);

        $page = new ViewEmailTokenPage(
            $emailToken,
            EmailTokenRepository::loadPolicy($emailToken),
            Stats::getActivity($emailToken->getId()),
            Stats::getHourlyVolume($emailToken->getId()),
            Stats::loadMessages(MessageFilter::createForEverything()->withEmailToken($emailToken->getId()), 25)
        );

        return self::renderPage($response, $emailToken->getName(), $page);
    }


    /**
     * Check whether the token's allowed senders can be sent as through each enabled provider, and the providers'
     * accounts (as JSON, for the sender checks on the token's page). This asks DNS and the providers' APIs, so it
     * takes a few seconds.
     */
    public function checkSenders(Request $request, Response $response, array $args) : Response
    {
        $emailToken = EmailTokenRepository::load($args['id']);
        $checks = SenderChecks::fetchForEnabledProviders();
        $groups = $checks->checkAllowedSenders(EmailTokenRepository::loadPolicy($emailToken)->getAllowedSenders());

        $groups = $groups->merge($checks->checkAccounts());
        return self::json($response, $groups->toResponseArray());
    }


    public function revoke(Request $request, Response $response, array $args) : Response
    {
        $emailToken = EmailTokenRepository::load($args['id']);
        $cancelled = EmailTokenRepository::revoke($emailToken);

        ServiceFactory::getLogger(LogCategory::ADMIN)->notice('Email token revoked', [
            'email_token_id' => $emailToken->getId(),
            'name' => $emailToken->getName(),
            'cancelled_queued_emails' => $cancelled,
            'by_user_id' => WebSession::getUser()->getId(),
        ]);

        $message = "The token \"{$emailToken->getName()}\" was revoked, and {$cancelled} queued emails were cancelled.";
        WebSession::flash($message);
        return self::redirect($response, "/email-tokens/{$emailToken->getId()}");
    }
}
