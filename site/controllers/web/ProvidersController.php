<?php

/*
 * Viewing and changing the providers email is sent through, and the mode for sharing emails between them (the
 * providers section of settings.yaml). They're edited as YAML, with secrets that are written out in full shown as
 * "<unchanged>" (and kept as they are, unless replaced). The whole of settings.yaml can be downloaded as a file that's
 * safe to commit to version control.
 */

declare(strict_types = 1);

use Programster\Http\HttpCode;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Interfaces\RouteCollectorProxyInterface;


class ProvidersController extends AbstractController
{
    public static function registerRoutes(RouteCollectorProxyInterface $group) : void
    {
        $group->get('/providers', [self::class, 'show'])->setName('providers');
        $group->post('/providers', [self::class, 'save'])->setName('providers.save');
        $group->get('/providers/export', [self::class, 'export'])->setName('providers.export');
        $group->get('/providers/sender-checks', [self::class, 'checkSenders'])->setName('providers.senderChecks');
        $group->get('/providers/new', [self::class, 'showNew'])->setName('providers.new');
        $group->post('/providers/new', [self::class, 'add'])->setName('providers.add');
        $group->post('/providers/mode', [self::class, 'setMode'])->setName('providers.mode');
        $group->post('/providers/order', [self::class, 'reorder'])->setName('providers.order');
        $group->get('/providers/{name}', [self::class, 'showProvider'])->setName('providers.provider');
        $group->get('/providers/{name}/edit', [self::class, 'showEdit'])->setName('providers.edit');
        $group->post('/providers/{name}/edit', [self::class, 'saveEdit'])->setName('providers.saveEdit');
        $group->post('/providers/{name}/remove', [self::class, 'remove'])->setName('providers.remove');
        $group->post('/providers/{name}/enable', [self::class, 'enable'])->setName('providers.enable');
        $group->post('/providers/{name}/disable', [self::class, 'disable'])->setName('providers.disable');
        $group->post('/providers/{name}/webhook', [self::class, 'setUpWebhook'])->setName('providers.webhook');
        $group->post('/providers/{name}/webhook/token', [self::class, 'generateWebhookToken'])
            ->setName('providers.webhookToken');
    }


    /**
     * Show one provider: its settings, rate limits, attempts and status, with buttons to edit and remove it.
     *
     * @throws NotFoundException - if there's no such provider.
     */
    public function showProvider(Request $request, Response $response, array $args) : Response
    {
        $provider = ProvidersFormService::loadProvider((string)$args['name']);
        $config = ProviderRegistry::getConfig();
        $page = new ViewProviderPage($provider, $config, self::loadActivity(), self::getProvidersVersionOrEmpty());
        return self::renderPage($response, $provider->getName(), $page);
    }


    public function showNew(Request $request, Response $response) : Response
    {
        $values = ProviderFormValues::createDefaults();
        $version = self::getProvidersVersionOrEmpty();
        $senders = ProviderSenderRows::createEmpty();
        $page = ViewProviderFormPage::createForAdding($values, new RateLimitCollection(), $senders, $version, '');
        return self::renderPage($response, 'Add a provider', $page);
    }


    public function add(Request $request, Response $response) : Response
    {
        $getField = self::createFieldReader($request);
        $version = self::getFormField($request, 'version');

        try
        {
            $type = self::readType($request);
            $entry = ProviderFormInput::readEntry($getField, $type, new ConfigSettingCollection());
            ProvidersFormService::add($entry, $version);
            self::rememberEnteredPassword($getField, $type, (string)$entry['name'], false);
            self::logChange('A provider was added', (string)$entry['name']);
            WebSession::flash("The provider \"{$entry['name']}\" was added.");
            $result = self::respondSaved($request, $response, '/providers');
        }
        catch (InvalidInputException | ConflictException | ConfigurationException $e)
        {
            $renderForm = function () use ($getField, $version, $e, $response) : Response {
                $values = ProviderFormValues::createFromForm($getField);
                $limits = self::readRateLimitsOrNone($getField);
                $senders = ProviderSenderRows::createUsingForm($getField);
                $page = ViewProviderFormPage::createForAdding($values, $limits, $senders, $version, $e->getMessage());
                return self::renderPage($response, 'Add a provider', $page, HttpCode::BAD_REQUEST);
            };

            $result = self::respondNotSaved($request, $response, $e, $renderForm);
        }

        return $result;
    }


    /**
     * @throws NotFoundException - if there's no such provider.
     */
    public function showEdit(Request $request, Response $response, array $args) : Response
    {
        $provider = ProvidersFormService::loadProvider((string)$args['name']);
        $values = ProviderFormValues::createFromProvider($provider);
        $limits = $provider->getRateLimits()->getLimits();
        $senders = ProviderSenderRows::createUsingRestriction($provider->getAllowedSenders());
        $version = self::getProvidersVersionOrEmpty();
        $page = ViewProviderFormPage::createForEditing($provider, $values, $limits, $senders, $version, '');
        return self::renderPage($response, "Edit {$provider->getName()}", $page);
    }


    /**
     * @throws NotFoundException - if there's no such provider.
     */
    public function saveEdit(Request $request, Response $response, array $args) : Response
    {
        $provider = ProvidersFormService::loadProvider((string)$args['name']);
        $getField = self::createFieldReader($request);
        $version = self::getFormField($request, 'version');

        try
        {
            $current = $provider->getSettings()->getSettings();
            $hasEntered = WebSession::hasEnteredProviderPassword($provider->getName());
            $reentry = $hasEntered ? PasswordReentry::NOT_REQUIRED : PasswordReentry::REQUIRED_FOR_NEW_SERVER;
            $entry = ProviderFormInput::readEntry($getField, $provider->getType(), $current, $reentry);
            ProvidersFormService::edit($provider->getName(), $entry, $version);
            self::rememberEnteredPassword($getField, $provider->getType(), (string)$entry['name'], $hasEntered);
            self::logChange('A provider was changed', (string)$entry['name']);
            WebSession::flash("The provider \"{$entry['name']}\" was saved.");
            $result = self::respondSaved($request, $response, '/providers/' . rawurlencode((string)$entry['name']));
        }
        catch (InvalidInputException | ConflictException | ConfigurationException $e)
        {
            $renderForm = function () use ($getField, $provider, $version, $e, $response) : Response {
                $values = ProviderFormValues::createFromForm($getField);
                $values['type'] = $provider->getType()->value;
                $limits = self::readRateLimitsOrNone($getField);
                $senders = ProviderSenderRows::createUsingForm($getField);
                $error = $e->getMessage();
                $page = ViewProviderFormPage::createForEditing($provider, $values, $limits, $senders, $version, $error);
                return self::renderPage($response, "Edit {$provider->getName()}", $page, HttpCode::BAD_REQUEST);
            };

            $result = self::respondNotSaved($request, $response, $e, $renderForm);
        }

        return $result;
    }


    /**
     * @throws NotFoundException - if there's no such provider.
     */
    public function remove(Request $request, Response $response, array $args) : Response
    {
        $name = (string)$args['name'];

        $remove = function () use ($name, $request) : string {
            $unlinked = ProvidersFormService::remove($name, self::getFormField($request, 'version'));
            self::logChange('A provider was removed', $name);
            return "The provider \"{$name}\" was removed." . self::describeUnlinkedTokens($unlinked);
        };

        return self::changeFromList($response, $remove);
    }


    /**
     * Enable a provider again, with the settings it had, and go back to its page.
     *
     * @throws NotFoundException - if there's no such provider.
     */
    public function enable(Request $request, Response $response, array $args) : Response
    {
        return self::setEnabled($request, $response, (string)$args['name'], true);
    }


    /**
     * Disable a provider, keeping its settings so it can be enabled again, and go back to its page.
     *
     * @throws NotFoundException - if there's no such provider.
     */
    public function disable(Request $request, Response $response, array $args) : Response
    {
        return self::setEnabled($request, $response, (string)$args['name'], false);
    }


    /**
     * Set up an SMTP2GO provider's webhook in its account, with its API key, and go back to its page.
     *
     * @throws NotFoundException - if there's no such provider.
     */
    public function setUpWebhook(Request $request, Response $response, array $args) : Response
    {
        $name = (string)$args['name'];

        try
        {
            Smtp2GoWebhookSetup::run($name, self::getFormField($request, 'version'));
            self::logChange('A provider\'s webhook was set up', $name);
            WebSession::flash("The webhook is set up: SMTP2GO will now report what happens to \"{$name}\"'s emails.");
        }
        catch (InvalidInputException | ConflictException | ConfigurationException $e)
        {
            WebSession::flash($e->getMessage(), true);
        }

        return self::redirect($response, '/providers/' . rawurlencode($name));
    }


    /**
     * Generate an SMTP2GO provider's webhook token, and show its page with the token, this once, and what to enter in
     * SMTP2GO. It's the response to the POST rather than a redirect, so the token is never kept in the session.
     *
     * @throws NotFoundException - if there's no such provider.
     */
    public function generateWebhookToken(Request $request, Response $response, array $args) : Response
    {
        $name = (string)$args['name'];
        $isConfirmed = (self::getFormField($request, 'confirm_replace') === '1');

        try
        {
            $token = Smtp2GoWebhookSetup::generateToken($name, self::getFormField($request, 'version'), $isConfirmed);
            self::logChange('A provider\'s webhook token was generated', $name);
            $provider = ProvidersFormService::loadProvider($name);
            $version = self::getProvidersVersionOrEmpty();
            $config = ProviderRegistry::getConfig();
            $page = new ViewProviderPage($provider, $config, self::loadActivity(), $version, $token);
            $result = self::renderPage($response, $name, $page)->withHeader('Cache-Control', 'no-store');
        }
        catch (InvalidInputException | ConflictException | ConfigurationException $e)
        {
            WebSession::flash($e->getMessage(), true);
            $result = self::redirect($response, '/providers/' . rawurlencode($name));
        }

        return $result;
    }


    /**
     * Put the enabled providers in the order they were dragged into, sent as "order_0", "order_1" and so on.
     */
    public function reorder(Request $request, Response $response) : Response
    {
        $reorder = function () use ($request) : string {
            $names = [];
            $index = 0;

            while (self::getFormField($request, "order_{$index}") !== '')
            {
                $names[] = self::getFormField($request, "order_{$index}");
                $index++;
            }

            ProvidersFormService::reorder(self::getFormField($request, 'version'), ...$names);
            self::logChange('The providers were put in a new order', implode(', ', $names));
            return 'The new order was saved.';
        };

        return self::changeFromList($response, $reorder);
    }


    public function setMode(Request $request, Response $response) : Response
    {
        $setMode = function () use ($request) : string {
            $mode = ProviderMode::tryFrom(self::getFormField($request, 'mode'));

            if ($mode === null)
            {
                throw new InvalidInputException('Choose how emails are shared between the providers.');
            }

            ProvidersFormService::setMode($mode, self::getFormField($request, 'version'));
            self::logChange("The providers' mode was set to {$mode->value}", '');
            return 'The mode was saved.';
        };

        return self::changeFromList($response, $setMode);
    }


    public function show(Request $request, Response $response) : Response
    {
        return self::renderProvidersPage($response, '', '', '', HttpCode::OK);
    }


    public function save(Request $request, Response $response) : Response
    {
        $yaml = self::getFormField($request, 'yaml');
        $version = self::getFormField($request, 'version');
        $scope = ProvidersEditScope::tryFrom(self::getFormField($request, 'scope')) ?? ProvidersEditScope::SECTION;

        try
        {
            if ($scope === ProvidersEditScope::WHOLE_FILE)
            {
                SettingsFile::saveWholeFile($yaml, $version);
                $config = ProviderRegistry::getConfig();
                $context = ['by_user_id' => WebSession::getUser()->getId()];
                $logger = ServiceFactory::getLogger(LogCategory::ADMIN);
                $logger->notice('settings.yaml was fixed, on the providers page', $context);
            }
            else
            {
                $config = SettingsFile::parseProvidersYaml($yaml);
                SettingsFile::saveProviders($config, $version);

                ServiceFactory::getLogger(LogCategory::ADMIN)->notice('The providers changed', [
                    'mode' => $config->getMode()->value,
                    'by_user_id' => WebSession::getUser()->getId(),
                ]);
            }

            // Email tokens restricted to a provider that's been removed (or given a different id) lose that link.
            $unlinked = EmailTokenRepository::removeLinksToOtherProviders($config->getIds());

            $message =
                'The providers were saved. The queue workers will use them from their next email.'
                . self::describeUnlinkedTokens($unlinked);

            WebSession::flash($message);
            $result = self::redirect($response, '/providers');
        }
        catch (InvalidInputException | ConflictException | ConfigurationException $e)
        {
            $error = $e->getMessage();
            $result = self::renderProvidersPage($response, $yaml, $version, $error, HttpCode::BAD_REQUEST, $scope);
        }

        return $result;
    }


    /**
     * Check each enabled provider's account, and whether every active token's allowed senders can be sent as through
     * them (as JSON, for the sender checks on the providers page). This asks DNS and the providers' APIs, so it
     * takes a few seconds.
     */
    public function checkSenders(Request $request, Response $response) : Response
    {
        $checks = SenderChecks::fetchForEnabledProviders(new AnyProvider());
        $groups = $checks->checkAccounts();
        $rules = [];
        $usedBy = [];
        $unrestricted = [];

        foreach (EmailTokenRepository::loadAll(false) as $emailToken)
        {
            /* @var $emailToken EmailToken */
            $senders = EmailTokenRepository::loadPolicy($emailToken)->getAllowedSenders();

            if ($senders instanceof AddressRuleSet)
            {
                foreach ($senders->getRules() as $rule)
                {
                    /* @var $rule AddressRule */
                    $rules[] = $rule;
                    $usedBy[(string)$rule][] = $emailToken->getName();
                }
            }
            else
            {
                $unrestricted[] = $emailToken->getName();
            }
        }

        if (count($unrestricted) > 0)
        {
            $groups = $groups->merge(new SenderCheckGroupCollection($checks->checkAnySender(...$unrestricted)));
        }

        if (count($rules) > 0)
        {
            $groups = $groups->merge($checks->checkSenders(new AddressRuleSet(...$rules), $usedBy));
        }

        return self::json($response, $groups->toResponseArray());
    }


    /**
     * Download settings.yaml (all the settings, including the providers) as a file that's safe to commit: secrets
     * written out in full are replaced with references to environment variables to put them in.
     */
    public function export(Request $request, Response $response) : Response
    {
        try
        {
            $response->getBody()->write(SettingsFile::export());

            $result = $response
                ->withHeader('Content-Type', 'application/yaml; charset=utf-8')
                ->withHeader('Content-Disposition', 'attachment; filename="settings.yaml"');
        }
        catch (ConfigurationException $e)
        {
            WebSession::flash("settings.yaml can't be downloaded until it's fixed: {$e->getMessage()}");
            $result = self::redirect($response, '/providers');
        }

        return $result;
    }


    /**
     * Load what the provider pages show about how the providers are being used.
     * @return ProviderActivity
     */
    private static function loadActivity() : ProviderActivity
    {
        $counts = ProviderStats::loadRecentCounts();
        $unavailable = ProviderStatusRepository::loadUnavailableDetails();
        return new ProviderActivity($counts, $unavailable, EmailTokenRepository::loadProviderLinks());
    }


    /**
     * Show the providers page: the providers, or the first provider to add on a fresh install, or what's wrong with
     * them, so it can be fixed.
     *
     * @param Response $response - the response.
     *
     * @param string $yaml - the YAML to fill the editor with, or an empty string for what's saved now.
     *
     * @param string $version - the version the YAML was based on, or an empty string for the current one.
     *
     * @param string $error - why saving failed, or an empty string.
     *
     * @param HttpCode $status - the HTTP status code.
     *
     * @param ProvidersEditScope $scope - what a failed save was saving, so the editor carries on editing it.
     *
     * @return Response
     */
    private static function renderProvidersPage(
        Response $response,
        string $yaml,
        string $version,
        string $error,
        HttpCode $status,
        ProvidersEditScope $scope = ProvidersEditScope::SECTION
    ) : Response
    {
        try
        {
            $config = ProviderRegistry::getConfig();
            $editorYaml = ($yaml !== '') ? $yaml : ProvidersConfigParser::toYaml($config, SecretOutput::MASKED);
            $editorVersion = ($version !== '') ? $version : SettingsFile::getSectionVersion(SettingsSection::PROVIDERS);
            $activity = self::loadActivity();
            $warning = SystemSenderWarning::describe($config);
            $page = new ViewProvidersPage($config, $activity, $warning, $editorYaml, $editorVersion, $error);
        }
        catch (ProvidersNotConfiguredException)
        {
            $editorYaml = ($yaml !== '') ? $yaml : ViewNoProvidersPage::EXAMPLE_YAML;
            $editorVersion = ($version !== '') ? $version : self::getProvidersVersionOrEmpty();
            $page = new ViewNoProvidersPage($editorYaml, $editorVersion, $error);
        }
        catch (SettingsFileInvalidException $e)
        {
            // The file as a whole can't be read (e.g. it was edited by hand), so show all of it as it is, to be fixed.
            $wholeFile = ProvidersEditScope::WHOLE_FILE;
            $isKeepingEdit = ($scope === $wholeFile && $yaml !== '');
            $editorYaml = $isKeepingEdit ? $yaml : self::readRawOrEmpty();
            $editorVersion = $isKeepingEdit ? $version : self::getFileVersionOrEmpty();
            $page = new ViewBrokenProvidersPage($e->getMessage(), $editorYaml, $editorVersion, $error, $wholeFile);
        }
        catch (ConfigurationException $e)
        {
            // The providers aren't valid (e.g. they were edited by hand), so show them as they're written, to be fixed.
            $editorYaml = ($yaml !== '') ? $yaml : SettingsFile::readSectionText(SettingsSection::PROVIDERS);
            $editorVersion = ($version !== '') ? $version : SettingsFile::getSectionVersion(SettingsSection::PROVIDERS);
            $section = ProvidersEditScope::SECTION;
            $page = new ViewBrokenProvidersPage($e->getMessage(), $editorYaml, $editorVersion, $error, $section);
        }

        return self::renderPage($response, 'Providers', $page, $status);
    }


    /**
     * Make a change from the list of providers (removing one, putting them in order, or setting the mode), and go back
     * to it, saying what was done, or why it couldn't be, e.g. that the last enabled provider can't be removed.
     *
     * @param Response $response - the response.
     *
     * @param Closure(): string $change - makes the change, and gives the message to show.
     *
     * @return Response
     *
     * @throws NotFoundException - if the change is to a provider that doesn't exist.
     */
    private static function changeFromList(Response $response, Closure $change) : Response
    {
        try
        {
            WebSession::flash($change());
        }
        catch (InvalidInputException | ConflictException | ConfigurationException $e)
        {
            WebSession::flash($e->getMessage(), true);
        }

        return self::redirect($response, '/providers');
    }


    /**
     * Respond to a provider being saved: with where to go next, as JSON, when the form was posted by its script
     * (ProviderForm.js), or by going there.
     *
     * @param Request $request - the request.
     *
     * @param Response $response - the response.
     *
     * @param string $path - where to go next, e.g. "/providers".
     *
     * @return Response
     */
    private static function respondSaved(Request $request, Response $response, string $path) : Response
    {
        $result = self::wantsJson($request)
            ? self::json($response, ['redirect' => $path])
            : self::redirect($response, $path);

        return $result;
    }


    /**
     * Respond to a provider that couldn't be saved. When the form was posted by its script (ProviderForm.js), that's
     * a JSON error, so the form stays as it was, with every secret still entered. Otherwise, it's the form again,
     * with the error, which can't fill the secrets back in, as they're never sent back to the browser.
     *
     * @param Request $request - the request.
     *
     * @param Response $response - the response.
     *
     * @param Exception $error - why it couldn't be saved.
     *
     * @param Closure(): Response $renderForm - renders the form again, with the error.
     *
     * @return Response
     */
    private static function respondNotSaved(
        Request $request,
        Response $response,
        Exception $error,
        Closure $renderForm
    ) : Response
    {
        if (self::wantsJson($request))
        {
            $isConflict = ($error instanceof ConflictException);
            $status = $isConflict ? HttpCode::CONFLICT : HttpCode::BAD_REQUEST;
            $code = $isConflict ? ErrorCodeEnum::CONFLICT : ErrorCodeEnum::INVALID_INPUT;
            $body = (new ApiException($status, $code, $error->getMessage()))->toArray();
            $result = self::json($response, $body, $status);
        }
        else
        {
            $result = $renderForm();
        }

        return $result;
    }


    /**
     * Enable or disable a provider, and go back to its page, saying what was done, or why it couldn't be (e.g. that
     * the last enabled provider can't be disabled). A toggle on the Providers page asks for JSON instead.
     *
     * @param Request $request - the request, with the version of the providers it's based on.
     *
     * @param Response $response - the response.
     *
     * @param string $name - the provider's name.
     *
     * @param bool $isEnabled - whether it's to be enabled.
     *
     * @return Response
     *
     * @throws NotFoundException - if there's no such provider.
     */
    private static function setEnabled(Request $request, Response $response, string $name, bool $isEnabled) : Response
    {
        $path = '/providers/' . rawurlencode($name);

        try
        {
            ProvidersFormService::setEnabled($name, $isEnabled, self::getFormField($request, 'version'));
            $done = $isEnabled ? 'enabled' : 'disabled';
            self::logChange("A provider was {$done}", $name);

            $message = $isEnabled
                ? "The provider \"{$name}\" was enabled: emails can be sent through it again."
                : "The provider \"{$name}\" was disabled. Its settings are kept, so it can be enabled again.";

            if (self::wantsJson($request))
            {
                $result = self::json($response, self::buildListState());
            }
            else
            {
                WebSession::flash($message);
                $result = self::redirect($response, $path);
            }
        }
        catch (InvalidInputException | ConflictException | ConfigurationException $e)
        {
            $showOnPage = function () use ($response, $e, $path) : Response {
                WebSession::flash($e->getMessage(), true);
                return self::redirect($response, $path);
            };

            $result = self::respondNotSaved($request, $response, $e, $showOnPage);
        }

        return $result;
    }


    /**
     * Build what the Providers page needs to stay correct after a provider's toggle is saved, without reloading: the
     * new version and YAML, and what each provider's Remove button should now warn about.
     *
     * @return array<string, mixed> - the JSON: "version", "yaml", and "providers", each with its "name", "warning"
     * and "isRefused".
     */
    private static function buildListState() : array
    {
        $config = ProviderRegistry::getConfig();
        $links = EmailTokenRepository::loadProviderLinks();
        $providers = [];

        foreach ($config->getProviders() as $provider)
        {
            /* @var $provider ProviderDefinition */
            $removal = ProviderRemovalNotice::createForProvider($provider, $config, $links);

            $providers[] = [
                'name' => $provider->getName(),
                'warning' => $removal->getMessage(),
                'isRefused' => $removal->isRefused(),
            ];
        }

        return [
            'version' => SettingsFile::getSectionVersion(SettingsSection::PROVIDERS),
            'yaml' => ProvidersConfigParser::toYaml($config, SecretOutput::MASKED),
            'providers' => $providers,
        ];
    }


    /**
     * Remember that this session entered a custom provider's password, once it's saved, so the session can change the
     * server it's sent to without entering it again (see WebSession::rememberEnteredProviderPassword()).
     *
     * @param Closure(string): string $getField - gets a form field's value by name.
     *
     * @param ProviderType $type - the provider's type.
     *
     * @param string $name - its name, as it's been saved.
     *
     * @param bool $hadEntered - whether this session had already entered it (under its name before the save, in case
     * it was renamed).
     *
     * @return void
     */
    private static function rememberEnteredPassword(
        Closure $getField,
        ProviderType $type,
        string $name,
        bool $hadEntered
    ) : void
    {
        if ($type === ProviderType::CUSTOM && ($hadEntered || ProviderFormInput::isCustomPasswordEntered($getField)))
        {
            WebSession::rememberEnteredProviderPassword($name);
        }
    }


    /**
     * @throws InvalidInputException - if the type isn't one of ProviderType's.
     */
    private static function readType(Request $request) : ProviderType
    {
        $type = ProviderType::tryFrom(self::getFormField($request, 'type'));

        if ($type === null)
        {
            throw new InvalidInputException('Choose the provider.');
        }

        return $type;
    }


    /**
     * Get a closure that gets one of a request's form fields, by name, for ProviderFormInput.
     * @return Closure(string): string
     */
    private static function createFieldReader(Request $request) : Closure
    {
        $getField = function (string $name) use ($request) : string {
            return self::getFormField($request, $name);
        };

        return $getField;
    }


    /**
     * Read the form's rate limits to fill it in with again, or none if they can't be read.
     *
     * @param Closure(string): string $getField - gets a form field's value by name.
     *
     * @return RateLimitCollection
     */
    private static function readRateLimitsOrNone(Closure $getField) : RateLimitCollection
    {
        try
        {
            $limits = PolicyInput::readRateLimitsFromForm($getField)->getLimits();
        }
        catch (InvalidInputException)
        {
            $limits = new RateLimitCollection();
        }

        return $limits;
    }


    private static function logChange(string $message, string $provider) : void
    {
        $context = ['provider' => $provider, 'by_user_id' => WebSession::getUser()->getId()];
        ServiceFactory::getLogger(LogCategory::ADMIN)->notice($message, $context);
    }


    private static function readRawOrEmpty() : string
    {
        try
        {
            $raw = SettingsFile::readRaw();
        }
        catch (ConfigurationException)
        {
            $raw = '';
        }

        return $raw;
    }


    private static function getFileVersionOrEmpty() : string
    {
        try
        {
            $version = SettingsFile::getFileVersion();
        }
        catch (ConfigurationException)
        {
            $version = hash('sha256', '');
        }

        return $version;
    }


    private static function getProvidersVersionOrEmpty() : string
    {
        try
        {
            $version = SettingsFile::getSectionVersion(SettingsSection::PROVIDERS);
        }
        catch (ConfigurationException)
        {
            $version = hash('sha256', '');
        }

        return $version;
    }


    /**
     * Describe the email tokens that lost their link to a provider when it was removed, to add to the message saying
     * it was saved.
     *
     * @param EmailTokenCollection $unlinked - the tokens.
     *
     * @return string - a sentence starting with a space, or an empty string if there are none.
     */
    private static function describeUnlinkedTokens(EmailTokenCollection $unlinked) : string
    {
        $names = [];

        foreach ($unlinked as $emailToken)
        {
            /* @var $emailToken EmailToken */
            $names[] = "\"{$emailToken->getName()}\"";
        }

        $description = '';

        if (count($names) > 0)
        {
            $description =
                ' These email tokens are no longer restricted to it, and only use the other providers they\'re'
                . ' restricted to (or none, so their emails wait in the queue): ' . implode(', ', $names) . '.';
        }

        return $description;
    }
}
