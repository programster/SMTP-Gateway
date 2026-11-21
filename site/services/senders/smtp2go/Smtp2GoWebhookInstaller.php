<?php

/*
 * Creates the webhook in an SMTP2GO account, or updates the one that already posts to this service, when asked to from
 * a provider's page. Unlike the sender checks, this changes the account, so it's separate from JsonApiClient, and only
 * ever runs when someone presses the button. The secret is the password in the webhook's address, which is how
 * SMTP2GO's documentation says to secure one.
 */

declare(strict_types = 1);

use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;


final readonly class Smtp2GoWebhookInstaller
{
    private const string API_URL = 'https://api.smtp2go.com/v3';
    private const int TIMEOUT_SECONDS = 15;

    private string $m_apiKey;
    private ClientInterface $m_http;
    private RequestFactoryInterface $m_requests;


    /**
     * @param string $apiKey - an API key allowed to view, add and edit webhooks.
     *
     * @param ClientInterface $http - the HTTP client to send the requests with.
     *
     * @param RequestFactoryInterface $requests - what to create the requests with.
     */
    public function __construct(string $apiKey, ClientInterface $http, RequestFactoryInterface $requests)
    {
        $this->m_apiKey = $apiKey;
        $this->m_http = $http;
        $this->m_requests = $requests;
    }


    /**
     * Create an installer that sends its requests with Guzzle.
     *
     * @param string $apiKey - an API key allowed to view, add and edit webhooks.
     *
     * @return Smtp2GoWebhookInstaller
     */
    public static function createUsingApiKey(string $apiKey) : Smtp2GoWebhookInstaller
    {
        $http = new \GuzzleHttp\Client(['timeout' => self::TIMEOUT_SECONDS, 'http_errors' => false]);
        return new Smtp2GoWebhookInstaller($apiKey, $http, new \GuzzleHttp\Psr7\HttpFactory());
    }


    /**
     * Make sure a webhook posts to an address with the secret, sending every event that's recorded.
     *
     * @param string $webhookUrl - where it's to post, without credentials. It must be https, as it carries the secret.
     *
     * @param string $secret - the provider's webhook_secret.
     *
     * @return void
     *
     * @throws InvalidInputException - if the address isn't https, or SMTP2GO refused, saying why.
     */
    public function install(string $webhookUrl, string $secret) : void
    {
        if (!str_starts_with($webhookUrl, 'https://'))
        {
            $msg =
                'The webhook\'s address must be public and https, as it carries the secret. Set PUBLIC_URL in .env to'
                . " this service's https address (it's \"{$webhookUrl}\" now).";

            throw new InvalidInputException($msg);
        }

        $existingId = 0;

        foreach (Smtp2GoWebhook::extractConfigured($this->post('/webhook/view', [])) as $webhook)
        {
            $isThisOne = (WebhookBasicAuth::removeCredentials((string)$webhook['url']) === rtrim($webhookUrl, '/'));

            if ($isThisOne && is_numeric($webhook['id'] ?? null))
            {
                $existingId = (int)$webhook['id'];
            }
        }

        $fields = [
            'url' => WebhookBasicAuth::createUrl($webhookUrl, $secret),
            'events' => Smtp2GoWebhook::RECORDED_EVENTS,
            'output_format' => 'json',
        ];

        if ($existingId > 0)
        {
            $this->post('/webhook/edit', ['id' => $existingId, 'disabled' => false] + $fields);
        }
        else
        {
            $this->post('/webhook/add', $fields);
        }
    }


    /**
     * Send a request to SMTP2GO's API.
     *
     * @param string $path - the endpoint.
     *
     * @param array<string, mixed> $body - its JSON body.
     *
     * @return array - the decoded response.
     *
     * @throws InvalidInputException - if it couldn't be reached, or refused the request, saying why.
     */
    private function post(string $path, array $body) : array
    {
        $json = ($body === []) ? '{}' : json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $request = $this->m_requests->createRequest('POST', self::API_URL . $path);
        $request->getBody()->write($json);

        $request = $request
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Accept', 'application/json')
            ->withHeader('X-Smtp2go-Api-Key', $this->m_apiKey);

        try
        {
            $response = $this->m_http->sendRequest($request);
        }
        catch (ClientExceptionInterface $e)
        {
            throw new InvalidInputException("SMTP2GO's API couldn't be reached: {$e->getMessage()}", 0, $e);
        }

        $status = $response->getStatusCode();
        $decoded = json_decode((string)$response->getBody(), true);
        $data = (is_array($decoded) && is_array($decoded['data'] ?? null)) ? $decoded['data'] : [];
        $code = is_string($data['error_code'] ?? null) ? $data['error_code'] : '';
        $error = is_string($data['error'] ?? null) ? $data['error'] : '';

        if ($status === 403 || str_contains($code, 'ENDPOINT_PERMISSION_DENIED'))
        {
            $msg =
                "The API key isn't allowed to use {$path}. Give it the webhook view, add and edit permissions"
                . ' (Sending > API Keys > Permissions in SMTP2GO), or set up the webhook by hand.';

            throw new InvalidInputException($msg);
        }
        elseif ($status < 200 || $status > 299 || !is_array($decoded))
        {
            $reason = ($error === '') ? "HTTP {$status}" : "{$error} (HTTP {$status})";
            throw new InvalidInputException("SMTP2GO refused {$path}: {$reason}.");
        }

        return $decoded;
    }
}
