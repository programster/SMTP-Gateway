<?php

/*
 * /api/v1/providers: reading and replacing the providers (the providers section of settings.yaml). Needs a management
 * API key. Before any have been set up (on a fresh install), reading them gives a "config" of null, and the version
 * to replace them with the first ones.
 *
 * Replacing needs an If-Match header with the version from a GET (its ETag), so two clients changing it at once
 * can't silently overwrite each other's changes. Secrets written out in full are returned as "<unchanged>", and are
 * kept if sent back that way.
 */

declare(strict_types = 1);

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Interfaces\RouteCollectorProxyInterface;


class ApiProvidersController extends AbstractController
{
    public static function registerRoutes(RouteCollectorProxyInterface $group) : void
    {
        $group->get('/providers', [self::class, 'get'])->setName('api.providers.get');
        $group->put('/providers', [self::class, 'replace'])->setName('api.providers.replace');
        $group->get('/providers/export', [self::class, 'export'])->setName('api.providers.export');
    }


    public function get(Request $request, Response $response) : Response
    {
        $version = SettingsFile::getSectionVersion(SettingsSection::PROVIDERS);

        try
        {
            $output = self::describeProviders(ProviderRegistry::getConfig(), $version);
        }
        catch (ProvidersNotConfiguredException)
        {
            $output = ['version' => $version, 'config' => null, 'status' => []];
        }

        return self::json($response, $output)->withHeader('ETag', '"' . $version . '"');
    }


    /**
     * Replace the providers, from JSON (the same structure as the "config" returned by GET), or YAML (with a
     * Content-Type of application/yaml).
     */
    public function replace(Request $request, Response $response) : Response
    {
        $version = trim($request->getHeaderLine('If-Match'), " \"");

        if ($version === '')
        {
            $msg = 'An If-Match header with the current version (the ETag from GET /providers) is required.';
            throw new ApiException(428, ErrorCodeEnum::MISSING_VERSION, $msg);
        }

        try
        {
            $isYaml = str_contains($request->getHeaderLine('Content-Type'), 'yaml');
            $body = (string)$request->getBody();
            $config = $isYaml
                ? SettingsFile::parseProvidersYaml($body)
                : SettingsFile::parseProvidersData(self::getJsonBody($request));

            $newVersion = SettingsFile::saveProviders($config, $version);
        }
        catch (InvalidInputException $e)
        {
            throw new ApiException(400, ErrorCodeEnum::INVALID_INPUT, $e->getMessage());
        }
        catch (ConflictException $e)
        {
            throw new ApiException(412, ErrorCodeEnum::CONFLICT, $e->getMessage());
        }

        $token = $request->getAttribute('api_key');

        ServiceFactory::getLogger(LogCategory::ADMIN)->notice('The providers changed through the API', [
            'mode' => $config->getMode()->value,
            'by_api_key' => ($token instanceof ApiKey) ? $token->getId() : '',
        ]);

        $output = ['version' => $newVersion, 'config' => ProvidersConfigParser::toArray($config, SecretOutput::MASKED)];
        return self::json($response, $output)->withHeader('ETag', '"' . $newVersion . '"');
    }


    /**
     * Get the providers as YAML (the providers section of settings.yaml), with every secret written out in full, as a
     * backup. It mustn't be committed to version control or shared.
     */
    public function export(Request $request, Response $response) : Response
    {
        try
        {
            $yaml = ProvidersConfigParser::toYaml(ProviderRegistry::getConfig(), SecretOutput::AS_IS);
        }
        catch (ProvidersNotConfiguredException $e)
        {
            throw new ApiException(404, ErrorCodeEnum::PROVIDERS_NOT_CONFIGURED, $e->getMessage());
        }

        $response->getBody()->write($yaml);
        return $response->withHeader('Content-Type', 'application/yaml; charset=utf-8');
    }


    /**
     * Describe the providers for GET: their configuration (with secrets masked), and whether each is being skipped.
     *
     * @param ProvidersConfig $config
     *
     * @param string $version - the providers section's version.
     *
     * @return array<string, mixed> - the response's JSON.
     */
    private static function describeProviders(ProvidersConfig $config, string $version) : array
    {
        $unavailable = ProviderStatusRepository::loadUnavailableDetails();
        $status = [];

        foreach ($config->getProviders() as $provider)
        {
            /* @var $provider ProviderDefinition */
            $name = $provider->getName();
            $isUnavailable = isset($unavailable[$name]);

            $status[] = [
                'name' => $name,
                'available' => $isUnavailable === false,
                'unavailableUntil' => $isUnavailable ? Time::toIso($unavailable[$name]['unavailable_until']) : null,
                'lastError' => $isUnavailable ? $unavailable[$name]['last_error'] : null,
            ];
        }

        return [
            'version' => $version,
            'config' => ProvidersConfigParser::toArray($config, SecretOutput::MASKED),
            'status' => $status,
        ];
    }
}
