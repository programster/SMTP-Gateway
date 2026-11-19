<?php

/*
 * The Settings pages, in the admin area: a page for each section of settings.yaml but the providers (which have a
 * page of their own), each saved on its own, and the read-only Server resources page. Every save is checked first,
 * and logged with who made it. A change applies to every process straight away, without a restart.
 */

declare(strict_types = 1);

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Interfaces\RouteCollectorProxyInterface;


class SettingsController extends AbstractController
{
    public const string CLOUDFLARE_FETCH_PATH = '/admin/settings/proxies/cloudflare-networks';
    public const string SERVER_RESOURCES_PATH = '/admin/settings/server-resources';


    public static function registerRoutes(RouteCollectorProxyInterface $group) : void
    {
        $group->get('/admin/settings', [self::class, 'showFirst'])->setName('admin.settings');

        $group->get(self::SERVER_RESOURCES_PATH, [self::class, 'showServerResources'])
            ->setName('admin.settings.server-resources');

        $group->get('/admin/settings/{section}', [self::class, 'show'])->setName('admin.settings.section');

        $saves = [
            SettingsSection::GENERAL->value => 'saveGeneral',
            SettingsSection::SECURITY->value => 'saveSecurity',
            SettingsSection::PROXIES->value => 'saveProxies',
            SettingsSection::SENDING->value => 'saveSending',
            SettingsSection::RETENTION->value => 'saveRetention',
            SettingsSection::GOOGLE_SSO->value => 'saveGoogleSso',
        ];

        foreach ($saves as $sectionKey => $method)
        {
            $section = SettingsSection::from($sectionKey);
            $group->post($section->getSavePath(), [self::class, $method])->setName("admin.settings.{$section->value}");
        }

        $group->post(self::CLOUDFLARE_FETCH_PATH, [self::class, 'fetchCloudflareNetworks'])
            ->setName('admin.settings.cloudflare-fetch');
    }


    /**
     * Fetch Cloudflare's address ranges now, rather than waiting for the next time they're fetched (on startup, and
     * every few hours), and go back to the Security settings, saying how it went.
     */
    public function fetchCloudflareNetworks(Request $request, Response $response) : Response
    {
        try
        {
            $count = CloudflareNetworks::fetchAndStore();
            WebSession::flash("Fetched Cloudflare's {$count} address ranges.");
        }
        catch (CloudflareFetchException $e)
        {
            WebSession::flash($e->getMessage() . ' The ranges fetched before are kept.', true);
        }

        return self::redirect($response, SettingsSection::PROXIES->getPagePath());
    }


    public function showFirst(Request $request, Response $response) : Response
    {
        return self::redirect($response, SettingsSection::GENERAL->getPagePath());
    }


    /**
     * Show one section's page, e.g. /admin/settings/general.
     *
     * @throws NotFoundException - if there's no such section, or it's the providers' (which have a page of their own).
     */
    public function show(Request $request, Response $response, array $args) : Response
    {
        $slug = (string)($args['section'] ?? '');
        $sections = SettingsSection::cases();
        $found = null;
        $index = 0;

        while ($found === null && $index < count($sections))
        {
            $section = $sections[$index];
            $isMatch = ($section !== SettingsSection::PROVIDERS && $section->getSlug() === $slug);
            $found = $isMatch ? $section : null;
            $index++;
        }

        if ($found === null)
        {
            throw new NotFoundException('There\'s no such settings page.');
        }

        // A section shown as part of another's page (e.g. the sending settings, on the General page) goes there.
        $isOwnPage = ($found->getPageSection() === $found);

        return $isOwnPage
            ? self::renderSettingsPage($response, $found, '', [], 200)
            : self::redirect($response, $found->getPagePath());
    }


    /**
     * Show the read-only Server resources page: the limits set in .env, and the values suggested for this server.
     */
    public function showServerResources(Request $request, Response $response) : Response
    {
        try
        {
            $page = new ViewServerResourcesPage(ResourceAdvisor::createUsingRunningService());
        }
        catch (Exception $e)
        {
            $context = ['error' => $e->getMessage()];
            $logger = ServiceFactory::getLogger(LogCategory::SYSTEM);
            $logger->warning('The server\'s resources couldn\'t be read', $context);
            $page = new ViewServerResourcesUnavailablePage($e->getMessage());
        }

        return self::renderPage($response, 'Server resources', $page);
    }


    public function saveGeneral(Request $request, Response $response) : Response
    {
        $save = function () use ($request) : void {
            // Each choice is from a dropdown, so one that isn't valid was made up, and is refused.
            $order = DateOrder::tryFrom(self::getFormField($request, 'date_order'));
            $separator = DateSeparator::tryFrom(self::getFormField($request, 'date_separator'));
            $position = TimePosition::tryFrom(self::getFormField($request, 'time_position'));

            if ($order === null || $separator === null || $position === null)
            {
                throw new InvalidInputException('Choose how dates and times are written from the lists.');
            }

            $format = new DateTimeFormat($order, $separator, $position);
            $settings = GeneralSettings::createUsingTimezoneName(self::getFormField($request, 'timezone'), $format);
            SettingsFile::saveGeneral($settings, self::getFormField($request, 'version'));
        };

        return self::save($request, $response, SettingsSection::GENERAL, $save);
    }


    public function saveSecurity(Request $request, Response $response) : Response
    {
        $save = function () use ($request) : void {
            $isTicked = function (string $name) use ($request) : bool {
                return self::getFormField($request, $name) === '1';
            };

            $passwordPolicy = new PasswordPolicy(
                self::readWholeNumber($request, 'password_min_length', 'A password\'s minimum length'),
                $isTicked('password_requires_capital'),
                $isTicked('password_requires_number'),
                $isTicked('password_requires_symbol')
            );

            $settings = new SecuritySettings($isTicked('require_mfa'), $passwordPolicy);
            SettingsFile::saveSecurity($settings, self::getFormField($request, 'version'));
        };

        return self::save($request, $response, SettingsSection::SECURITY, $save);
    }


    public function saveProxies(Request $request, Response $response) : Response
    {
        $save = function () use ($request) : void {
            $proxies = [];

            $getField = function (string $name) use ($request) : string {
                return self::getFormField($request, $name);
            };

            // Its rows (see ViewValueRows), each an address or range, saying which row is wrong. It can be empty.
            foreach (PolicyInput::readValueRowsFromForm($getField, 'trusted_proxies') as $row => $value)
            {
                try
                {
                    $proxies[] = new IpNetwork($value);
                }
                catch (InvalidInputException $e)
                {
                    throw new InvalidInputException("Trusted proxies, row {$row}: {$e->getMessage()}");
                }
            }

            $isBehindCloudflare = (self::getFormField($request, 'behind_cloudflare') === '1');
            $settings = new ProxySettings($isBehindCloudflare, new IpNetworkCollection(...$proxies));
            SettingsFile::saveProxies($settings, self::getFormField($request, 'version'));

            // Once it's turned on, Cloudflare's ranges are needed straight away, rather than at the next fetch. If
            // they can't be fetched, the page says so, with a button to try again.
            if ($isBehindCloudflare && CloudflareNetworks::loadStatus()->hasBeenFetched() === false)
            {
                self::tryFetchingCloudflareNetworks();
            }
        };

        return self::save($request, $response, SettingsSection::PROXIES, $save);
    }


    public function saveSending(Request $request, Response $response) : Response
    {
        $save = function () use ($request) : void {
            $waitSeconds = self::readWholeNumber($request, 'sync_wait_seconds', 'How long a client waits');
            $settings = new SendingSettings(self::getFormField($request, 'system_email_from'), $waitSeconds);
            SettingsFile::saveSending($settings, self::getFormField($request, 'version'));
        };

        return self::save($request, $response, SettingsSection::SENDING, $save);
    }


    public function saveRetention(Request $request, Response $response) : Response
    {
        $save = function () use ($request) : void {
            $recordDays = self::readWholeNumber($request, 'email_record_days', 'How long email records are kept');
            $content = self::readRetentionSetting($request, 'content', 'How long email content is kept');
            $attachments = self::readRetentionSetting($request, 'attachment', 'How long attachments are kept');
            $policy = EmailRetentionPolicy::createFromSettings((string)$recordDays, $content, $attachments);
            $logDays = self::readWholeNumber($request, 'log_days', 'How long the logs are kept');
            $settings = new RetentionSettings($policy, $logDays);
            SettingsFile::saveRetention($settings, self::getFormField($request, 'version'));
        };

        return self::save($request, $response, SettingsSection::RETENTION, $save);
    }


    public function saveGoogleSso(Request $request, Response $response) : Response
    {
        $save = function () use ($request) : void {
            $bypass = SsoMfaBypass::tryFrom(self::getFormField($request, 'bypass_mfa'));

            if ($bypass === null)
            {
                throw new InvalidInputException('Choose whether logging in with Google skips the second factor.');
            }

            // The secret is never shown, so an empty field keeps the one there is.
            $secretField = trim(self::getFormField($request, 'client_secret'));

            $secret = ($secretField === '')
                ? Settings::get()->getGoogleSso()->getClientSecret()
                : ConfigValue::createFromString($secretField);

            $config = new GoogleSsoConfig(
                self::getFormField($request, 'enabled') === '1',
                self::getFormField($request, 'client_id'),
                $secret,
                $bypass
            );

            SettingsFile::saveGoogleSso($config, self::getFormField($request, 'version'));
        };

        return self::save($request, $response, SettingsSection::GOOGLE_SSO, $save);
    }


    /**
     * Save a section from its form, then go back to the page (saying it was saved), or show the page again with why
     * it couldn't be, and what was sent, to be corrected.
     *
     * @param Request $request - the form's request.
     *
     * @param Response $response - the response.
     *
     * @param SettingsSection $section - the section the form is for.
     *
     * @param Closure $save - reads the form and saves the section.
     *
     * @return Response
     */
    private static function save(
        Request $request,
        Response $response,
        SettingsSection $section,
        Closure $save
    ) : Response
    {
        try
        {
            $save();

            ServiceFactory::getLogger(LogCategory::ADMIN)->notice('Settings changed', [
                'section' => $section->value,
                'by_user_id' => WebSession::getUser()->getId(),
            ]);

            WebSession::flash('The settings were saved. They apply straight away.');
            $result = self::redirect($response, $section->getPagePath());
        }
        catch (InvalidInputException | ConflictException | ConfigurationException $e)
        {
            $body = $request->getParsedBody();
            $submitted = [];

            foreach (is_array($body) ? $body : [] as $name => $value)
            {
                // The secret is never sent back to the page, so it can't end up in its HTML.
                if (is_string($value) && $name !== 'client_secret')
                {
                    $submitted[(string)$name] = $value;
                }
            }

            $result = self::renderSettingsPage($response, $section, $e->getMessage(), $submitted, 400);
        }

        return $result;
    }


    /**
     * @param Response $response - the response.
     *
     * @param SettingsSection $section - the section to show, which is the one whose form failed to save, if any.
     *
     * @param string $error - why it failed to save, or an empty string.
     *
     * @param array<string, string> $submitted - what its form sent, to fill it in with again.
     *
     * @param int $status - the HTTP status code.
     *
     * @return Response
     */
    private static function tryFetchingCloudflareNetworks() : void
    {
        try
        {
            CloudflareNetworks::fetchAndStore();
        }
        catch (CloudflareFetchException $e)
        {
            $logger = ServiceFactory::getLogger(LogCategory::SYSTEM);

            $logger->warning('Cloudflare\'s address ranges couldn\'t be fetched', [
                'error' => $e->getMessage(),
            ]);
        }
    }


    private static function renderSettingsPage(
        Response $response,
        SettingsSection $section,
        string $error,
        array $submitted,
        int $status
    ) : Response
    {
        $page = new ViewSettingsPage(Settings::get(), $section, $error, $submitted);
        return self::renderPage($response, "Settings: {$section->getPageSection()->getTitle()}", $page, $status);
    }


    /**
     * @throws InvalidInputException - if the field isn't a whole number.
     */
    private static function readWholeNumber(Request $request, string $name, string $label) : int
    {
        $value = trim(self::getFormField($request, $name));

        if (preg_match('/^-?[0-9]{1,6}$/', $value) !== 1)
        {
            throw new InvalidInputException("{$label} must be a whole number, not \"{$value}\".");
        }

        return (int)$value;
    }


    /**
     * Read how long a part of each email is kept, from its form's choice and number of days, as the setting it
     * stands for: "-1" (not kept), "0" (as long as what it's part of), or a number of days.
     *
     * @throws InvalidInputException - if the choice isn't one of RetentionMode's, or the days aren't a whole number.
     */
    private static function readRetentionSetting(Request $request, string $prefix, string $label) : string
    {
        $mode = RetentionMode::tryFrom(self::getFormField($request, "{$prefix}_mode"));

        if ($mode === null)
        {
            throw new InvalidInputException("Choose {$label}.");
        }

        return match ($mode) {
            RetentionMode::NOT_KEPT => '-1',
            RetentionMode::KEPT_WITH_PARENT => '0',
            RetentionMode::KEPT_FOR_DAYS => (string)self::readWholeNumber($request, "{$prefix}_days", $label),
        };
    }
}
