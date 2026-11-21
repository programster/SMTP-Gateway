<?php

/*
 * Reads from a provider's JSON web API, for the sender checks (sending itself goes through each provider's SMTP
 * relay). It only makes GET requests, or POST requests to the view-only endpoints in ReadOnlyPostEndpoint, so it can't
 * change anything in the provider's account.
 */

declare(strict_types = 1);

use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;


final readonly class JsonApiClient
{
    private const int TIMEOUT_SECONDS = 10;

    private string $m_label;
    private string $m_baseUrl;
    private array $m_headers;
    private ClientInterface $m_http;
    private RequestFactoryInterface $m_requests;


    /**
     * @param string $label - the provider's own name, for error messages, e.g. "SendGrid".
     *
     * @param string $baseUrl - the API's address, without a trailing slash, e.g. "https://api.sendgrid.com".
     *
     * @param array<string, string> $headers - the headers that authenticate each request, by name, e.g.
     * ["Authorization" => "Bearer SG.xxx"].
     *
     * @param ClientInterface $http - the HTTP client to send the requests with.
     *
     * @param RequestFactoryInterface $requests - what to create the requests with.
     *
     * @throws InvalidInputException - if the address isn't https (the headers hold the API key).
     */
    public function __construct(
        string $label,
        string $baseUrl,
        array $headers,
        ClientInterface $http,
        RequestFactoryInterface $requests
    )
    {
        if (!str_starts_with($baseUrl, 'https://'))
        {
            throw new InvalidInputException("{$label}'s API must be reached over https, not {$baseUrl}.");
        }

        $this->m_label = $label;
        $this->m_baseUrl = rtrim($baseUrl, '/');
        $this->m_headers = $headers;
        $this->m_http = $http;
        $this->m_requests = $requests;
    }


    /**
     * Create a client that sends its requests with Guzzle.
     *
     * @param string $label - the provider's own name, e.g. "SendGrid".
     *
     * @param string $baseUrl - the API's address, e.g. "https://api.sendgrid.com".
     *
     * @param array<string, string> $headers - the headers that authenticate each request, by name.
     *
     * @return JsonApiClient
     */
    public static function create(string $label, string $baseUrl, array $headers) : JsonApiClient
    {
        $http = new \GuzzleHttp\Client(['timeout' => self::TIMEOUT_SECONDS, 'http_errors' => false]);
        return new JsonApiClient($label, $baseUrl, $headers, $http, new \GuzzleHttp\Psr7\HttpFactory());
    }


    /**
     * Fetch something from the API.
     *
     * @param string $path - the path and query string, e.g. "/v3/scopes".
     *
     * @return array - the decoded JSON response.
     *
     * @throws ProviderPermissionException - if the API key isn't allowed to read it.
     *
     * @throws ProviderNotFoundException - if it doesn't exist.
     *
     * @throws ProviderLookupException - if the API couldn't be reached, rejected the API key, or didn't answer with
     * JSON.
     */
    public function fetchJson(string $path) : array
    {
        $request = $this->m_requests->createRequest('GET', $this->m_baseUrl . $path);
        return $this->sendForJson($request, $path);
    }


    /**
     * Fetch something from an API that needs POST even to read, with an empty JSON object as the request's body.
     *
     * @param ReadOnlyPostEndpoint $endpoint - the view-only endpoint.
     *
     * @return array - the decoded JSON response.
     *
     * @throws ProviderPermissionException - if the API key isn't allowed to read it.
     *
     * @throws ProviderNotFoundException - if it doesn't exist.
     *
     * @throws ProviderLookupException - if the API couldn't be reached, rejected the API key, or didn't answer with
     * JSON.
     */
    public function fetchJsonUsingPost(ReadOnlyPostEndpoint $endpoint) : array
    {
        $path = $endpoint->value;
        $request = $this->m_requests->createRequest('POST', $this->m_baseUrl . $path);
        $request->getBody()->write('{}');
        return $this->sendForJson($request->withHeader('Content-Type', 'application/json'), $path);
    }


    /**
     * Send a request with the authenticating headers, and read its JSON response.
     *
     * @param RequestInterface $request - the request.
     *
     * @param string $path - its path, for error messages.
     *
     * @return array - the decoded JSON response.
     *
     * @throws ProviderPermissionException - if the API key isn't allowed to read it.
     *
     * @throws ProviderNotFoundException - if it doesn't exist.
     *
     * @throws ProviderLookupException - if the API couldn't be reached, rejected the API key, or didn't answer with
     * JSON.
     */
    private function sendForJson(RequestInterface $request, string $path) : array
    {
        $request = $request->withHeader('Accept', 'application/json');

        foreach ($this->m_headers as $name => $value)
        {
            $request = $request->withHeader($name, $value);
        }

        try
        {
            $response = $this->m_http->sendRequest($request);
        }
        catch (ClientExceptionInterface $e)
        {
            throw new ProviderLookupException("{$this->m_label}'s API couldn't be reached: {$e->getMessage()}", 0, $e);
        }

        $status = $response->getStatusCode();
        $data = json_decode((string)$response->getBody(), true);

        if ($status === 401)
        {
            throw new ProviderLookupException("{$this->m_label} rejected the API key (it may have been deleted).");
        }
        elseif (self::isPermissionDenied($status, $data))
        {
            throw new ProviderPermissionException("The API key isn't allowed to read {$path}.");
        }
        elseif ($status === 404)
        {
            throw new ProviderNotFoundException("{$this->m_label}'s API has no {$path}.");
        }
        elseif ($status < 200 || $status > 299 || !is_array($data))
        {
            throw new ProviderLookupException("{$this->m_label}'s API answered {$path} with HTTP {$status}.");
        }

        return $data;
    }


    /**
     * Check whether a response says the API key isn't allowed to do this: an HTTP 403, or the HTTP 400 with an
     * ENDPOINT_PERMISSION_DENIED error code that SMTP2GO's API reference shows.
     *
     * @param int $status - the HTTP status.
     *
     * @param mixed $data - the decoded response, which can be anything if it wasn't the expected JSON.
     *
     * @return bool
     */
    private static function isPermissionDenied(int $status, mixed $data) : bool
    {
        $details = is_array($data) ? ($data['data'] ?? $data) : [];
        $code = is_array($details) ? ($details['error_code'] ?? '') : '';
        $isDeniedEndpoint = ($status === 400 && is_string($code) && str_contains($code, 'ENDPOINT_PERMISSION_DENIED'));
        return ($status === 403 || $isDeniedEndpoint);
    }
}
