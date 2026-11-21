<?php

/*
 * Creating, viewing, changing and revoking the email tokens that other services send email with.
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

        $group->get('/email-tokens/{id}/edit', [self::class, 'showEdit'])->setName('email-tokens.edit');
        $group->post('/email-tokens/{id}/edit', [self::class, 'saveEdit'])->setName('email-tokens.saveEdit');

        $group->get('/email-tokens/{id}/queued-emails', [self::class, 'countQueuedEmails'])
            ->setName('email-tokens.queuedEmails');

        $group->post('/email-tokens/{id}/revoke', [self::class, 'revoke'])->setName('email-tokens.revoke');
    }


    public function list(Request $request, Response $response) : Response
    {
        $isShowingRevoked = (self::getQueryParam($request, 'revoked') === '1');
        $page = new ViewEmailTokensPage(EmailTokenRepository::loadAll(), $isShowingRevoked);
        return self::renderPage($response, 'Email tokens', $page);
    }


    public function showNew(Request $request, Response $response) : Response
    {
        // The first row of the IP addresses (see ViewValueRows): the address being used now. The address lists start
        // empty, which allows anyone.
        $defaults = ['allowed_ips_0' => (string)ClientIpResolver::resolve($request)];

        $page = new ViewNewEmailTokenPage($defaults, '', self::getSiteUrl($request), self::loadProviders());
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
            $siteUrl = self::getSiteUrl($request);
            $page = new ViewNewEmailTokenPage($values, $e->getMessage(), $siteUrl, self::loadProviders());
            $result = self::renderPage($response, 'New email token', $page, 400);
        }

        return $result;
    }


    public function show(Request $request, Response $response, array $args) : Response
    {
        $emailToken = EmailTokenRepository::load($args['id']);
        $policy = EmailTokenRepository::loadPolicy($emailToken);

        $page = new ViewEmailTokenPage(
            $emailToken,
            $policy,
            new ProviderRestrictionSummary($policy->getAllowedProviders(), self::loadProviders()),
            Stats::getActivity($emailToken->getId()),
            Stats::getHourlyVolume($emailToken->getId()),
            Stats::loadMessages(MessageFilter::createForEverything()->withEmailToken($emailToken->getId()), 25)
        );

        return self::renderPage($response, $emailToken->getName(), $page);
    }


    /**
     * Show the form for changing a token's name and restrictions, filled in with them as they are now. A revoked token
     * can't be changed, so that goes back to its page, saying so.
     *
     * @throws NotFoundException - if there's no such token.
     */
    public function showEdit(Request $request, Response $response, array $args) : Response
    {
        $emailToken = EmailTokenRepository::load($args['id']);

        if ($emailToken->isRevoked())
        {
            WebSession::flash('This email token has been revoked, so it can\'t be changed.', true);
            $result = self::redirect($response, '/email-tokens/' . rawurlencode((string)$emailToken->getId()));
        }
        else
        {
            $values = EmailTokenFormValues::createFromToken($emailToken, EmailTokenRepository::loadPolicy($emailToken));
            $page = new ViewEditEmailTokenPage($emailToken, $values, '', self::loadProviders());
            $result = self::renderPage($response, "Edit {$emailToken->getName()}", $page);
        }

        return $result;
    }


    /**
     * Save a token's new name and restrictions, and go back to its page. If they're invalid, the form is shown again
     * with what was sent, and why.
     *
     * @throws NotFoundException - if there's no such token.
     */
    public function saveEdit(Request $request, Response $response, array $args) : Response
    {
        $emailToken = EmailTokenRepository::load($args['id']);
        $tokenPath = '/email-tokens/' . rawurlencode((string)$emailToken->getId());

        $getField = function (string $name) use ($request) : string {
            return self::getFormField($request, $name);
        };

        try
        {
            $name = self::getFormField($request, 'name');
            $policy = PolicyInput::readPolicyFromForm($getField);
            EmailTokenRepository::update($emailToken, $name, $policy);

            ServiceFactory::getLogger(LogCategory::ADMIN)->info('Email token changed', [
                'email_token_id' => $emailToken->getId(),
                'name' => trim($name),
                'by_user_id' => WebSession::getUser()->getId(),
            ]);

            WebSession::flash('The email token was saved.');
            $result = self::redirect($response, $tokenPath);
        }
        catch (InvalidInputException $e)
        {
            $body = $request->getParsedBody();
            $values = is_array($body) ? array_filter($body, 'is_string') : [];
            $page = new ViewEditEmailTokenPage($emailToken, $values, $e->getMessage(), self::loadProviders());
            $result = self::renderPage($response, "Edit {$emailToken->getName()}", $page, 400);
        }
        catch (ConflictException $e)
        {
            WebSession::flash($e->getMessage(), true);
            $result = self::redirect($response, $tokenPath);
        }

        return $result;
    }


    /**
     * Check whether the token's allowed senders can be sent as through each enabled provider it may use, and those
     * providers' accounts (as JSON, for the sender checks on the token's page). This asks DNS and the providers' APIs,
     * so it takes a few seconds.
     */
    public function checkSenders(Request $request, Response $response, array $args) : Response
    {
        $emailToken = EmailTokenRepository::load($args['id']);
        $policy = EmailTokenRepository::loadPolicy($emailToken);
        // Only the providers its emails can go through.
        $checks = SenderChecks::fetchForEnabledProviders($policy->getAllowedProviders());
        $groups = $checks->checkAllowedSenders($policy->getAllowedSenders());

        $groups = $groups->merge($checks->checkAccounts());
        return self::json($response, $groups->toResponseArray());
    }


    /**
     * Count the token's emails waiting in the queue, which revoking it would cancel (as JSON, for the dialog that asks
     * before revoking it).
     *
     * @throws NotFoundException - if there's no such token.
     */
    public function countQueuedEmails(Request $request, Response $response, array $args) : Response
    {
        $emailToken = EmailTokenRepository::load($args['id']);
        return self::json($response, ['queuedEmails' => EmailTokenRepository::countQueuedEmails($emailToken)]);
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


    /**
     * Get the providers a token can be restricted to on its form: every one in settings.yaml, enabled or not, or none
     * before any have been set up (or while settings.yaml can't be read, which the Providers page explains).
     * @return ProviderDefinitionCollection
     */
    private static function loadProviders() : ProviderDefinitionCollection
    {
        try
        {
            $providers = ProviderRegistry::getConfig()->getProviders();
        }
        catch (ConfigurationException)
        {
            $providers = new ProviderDefinitionCollection();
        }

        return $providers;
    }
}
