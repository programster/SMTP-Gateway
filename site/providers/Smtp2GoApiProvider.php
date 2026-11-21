<?php

/*
 * Sends email through SMTP2GO's API (/email/mime), for a provider with an API key but no SMTP login.
 *
 * Unlike SMTP, the API has no separate list of recipients: SMTP2GO delivers to the addresses in the email's To, Cc and
 * Bcc headers. So a recipient who isn't in To or Cc is put in a Bcc header (which SMTP2GO removes), and an email whose
 * To or Cc lists someone who isn't one of its recipients is refused, as it would otherwise be delivered to them.
 *
 * SMTP2GO also replaces the envelope sender with its own bounce address, on this route and on SMTP.
 */

declare(strict_types = 1);

use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ServerRequestInterface;


final class Smtp2GoApiProvider implements SmtpProviderInterface, ProviderWebhookInterface
{
    private const string API_URL = 'https://api.smtp2go.com/v3/email/mime';
    private const int TIMEOUT_SECONDS = 30;

    // The most addresses SMTP2GO's API takes in one of To, Cc or Bcc.
    private const int MAX_BCC_RECIPIENTS = 100;

    private readonly string $m_name;
    private readonly string $m_apiKey;
    private readonly Smtp2GoWebhook $m_webhook;
    private readonly ClientInterface $m_http;
    private readonly RequestFactoryInterface $m_requests;


    /**
     * @param string $name - the provider's name in settings.yaml. Its webhook is at /webhooks/<name>.
     *
     * @param string $apiKey - an API key allowed to use /email/mime.
     *
     * @param string $webhookSecret - the secret its webhook authenticates with, or an empty string to leave the webhook
     * switched off.
     *
     * @param ClientInterface $http - the HTTP client to send the requests with.
     *
     * @param RequestFactoryInterface $requests - what to create the requests with.
     *
     * @throws ConfigurationException - if the API key is empty, or the secret is too short.
     */
    public function __construct(
        string $name,
        string $apiKey,
        string $webhookSecret,
        ClientInterface $http,
        RequestFactoryInterface $requests
    )
    {
        if (trim($apiKey) === '')
        {
            throw new ConfigurationException("The SMTP2GO provider \"{$name}\" needs an api_key to send with its API.");
        }

        $this->m_name = $name;
        $this->m_apiKey = trim($apiKey);
        $this->m_webhook = new Smtp2GoWebhook($name, $webhookSecret);
        $this->m_http = $http;
        $this->m_requests = $requests;
    }


    /**
     * Create a provider that sends its requests with Guzzle.
     *
     * @param string $name - the provider's name in settings.yaml.
     *
     * @param string $apiKey - an API key allowed to use /email/mime.
     *
     * @param string $webhookSecret - the secret its webhook authenticates with, or an empty string.
     *
     * @return Smtp2GoApiProvider
     *
     * @throws ConfigurationException - if the API key is empty, or the secret is too short.
     */
    public static function createUsingApiKey(string $name, string $apiKey, string $webhookSecret) : Smtp2GoApiProvider
    {
        $http = new \GuzzleHttp\Client(['timeout' => self::TIMEOUT_SECONDS, 'http_errors' => false]);
        return new Smtp2GoApiProvider($name, $apiKey, $webhookSecret, $http, new \GuzzleHttp\Psr7\HttpFactory());
    }


    public function getName() : string
    {
        return $this->m_name;
    }


    public function getType() : ProviderType
    {
        return ProviderType::SMTP2GO;
    }


    /**
     * Send an email through the API.
     *
     * @param OutgoingEmail $email - the email, and who to deliver it to.
     *
     * @return string - SMTP2GO's ID for the email.
     *
     * @throws ProviderUnavailableException - if the API couldn't be reached, or the key can't send.
     *
     * @throws ProviderRateLimitedException - if SMTP2GO's rate limit was reached.
     *
     * @throws ProviderTemporaryFailureException - if SMTP2GO had a server error.
     *
     * @throws ProviderPermanentFailureException - if SMTP2GO refused it, or its headers can't say who it's for.
     */
    public function send(OutgoingEmail $email) : string
    {
        $payload = json_encode([
            'mime_email' => base64_encode(self::prepareEmail($email)->toString()),
            'fastaccept' => false,
        ], JSON_THROW_ON_ERROR);

        $request = $this->m_requests->createRequest('POST', self::API_URL);
        $request->getBody()->write($payload);

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
            throw new ProviderUnavailableException("SMTP2GO's API couldn't be reached: {$e->getMessage()}", 0, $e);
        }

        return self::readEmailId($response->getStatusCode(), (string)$response->getBody());
    }


    public function disconnect() : void
    {
        // Each email is its own HTTP request, so there's no connection to close.
    }


    public function isWebhookEnabled() : bool
    {
        return $this->m_webhook->isEnabled();
    }


    public function parseWebhook(string $body, ServerRequestInterface $request) : ProviderEventCollection
    {
        return $this->m_webhook->parse($body, $request);
    }


    /**
     * Get the email to send: without any of the client's X-Smtp2go-* headers, and with exactly its recipients in its
     * To, Cc and Bcc headers.
     *
     * @param OutgoingEmail $email - the email.
     *
     * @return RawEmail
     *
     * @throws ProviderPermanentFailureException - if its To or Cc can't be read, lists someone who isn't a recipient,
     * or there are more hidden recipients than the API takes.
     */
    private static function prepareEmail(OutgoingEmail $email) : RawEmail
    {
        $raw = $email->getRawEmail()->withoutHeadersStartingWith('X-Smtp2go-')->withoutHeader('Bcc');
        $recipients = [];

        foreach ($email->getRecipients() as $recipient)
        {
            /* @var $recipient EmailAddress */
            $recipients[mb_strtolower((string)$recipient)] = $recipient;
        }

        try
        {
            $visible = [...$raw->getAddressesInHeader('To'), ...$raw->getAddressesInHeader('Cc')];
        }
        catch (InvalidInputException $e)
        {
            $msg = "SMTP2GO's API needs to read who it's for from its To and Cc headers: {$e->getMessage()}";
            throw new ProviderPermanentFailureException($msg, 0, $e);
        }

        $hidden = $recipients;
        $notRecipients = [];

        foreach ($visible as $address)
        {
            /* @var $address EmailAddress */
            $key = mb_strtolower((string)$address);

            if (isset($recipients[$key]))
            {
                unset($hidden[$key]);
            }
            else
            {
                $notRecipients[] = (string)$address;
            }
        }

        if (count($notRecipients) > 0)
        {
            $msg =
                'SMTP2GO\'s API would also deliver this to ' . implode(', ', $notRecipients) . ', who are in its To or'
                . ' Cc but aren\'t its recipients. Add an SMTP login to this provider to send emails like this.';

            throw new ProviderPermanentFailureException($msg);
        }

        if (count($hidden) > self::MAX_BCC_RECIPIENTS)
        {
            $msg =
                'SMTP2GO\'s API takes at most ' . self::MAX_BCC_RECIPIENTS . ' Bcc recipients, and this has '
                . count($hidden) . '. Add an SMTP login to this provider to send emails like this.';

            throw new ProviderPermanentFailureException($msg);
        }

        return (count($hidden) > 0) ? $raw->withAddressHeaderPrepended('Bcc', ...array_values($hidden)) : $raw;
    }


    /**
     * Read the API's response, which can be a 200 even when the email failed.
     *
     * @param int $status - the HTTP status.
     *
     * @param string $body - the response's body.
     *
     * @return string - SMTP2GO's ID for the email, or an empty string if it didn't give one.
     *
     * @throws ProviderUnavailableException - if the key was rejected, or isn't allowed to send.
     * @throws ProviderRateLimitedException - if its rate limit was reached.
     * @throws ProviderTemporaryFailureException - if it had a server error.
     * @throws ProviderPermanentFailureException - if it refused the email.
     */
    private static function readEmailId(int $status, string $body) : string
    {
        $decoded = json_decode($body, true);
        $data = (is_array($decoded) && is_array($decoded['data'] ?? null)) ? $decoded['data'] : [];
        $code = is_string($data['error_code'] ?? null) ? $data['error_code'] : '';
        $error = is_string($data['error'] ?? null) ? $data['error'] : '';
        $detail = trim("{$code} {$error}");
        $message = "SMTP2GO's API answered HTTP {$status}" . (($detail === '') ? '' : ": {$detail}");
        $isDenied = ($status === 400 && str_contains($code, 'ENDPOINT_PERMISSION_DENIED'));

        if ($status === 429 || RateLimitReply::isRateLimit((string)$status, $detail))
        {
            throw new ProviderRateLimitedException($message);
        }
        elseif ($status === 401 || $status === 403 || $isDenied)
        {
            throw new ProviderUnavailableException("{$message}. Check the API key is allowed to use /email/mime.");
        }
        elseif ($status >= 500)
        {
            throw new ProviderTemporaryFailureException($message);
        }
        elseif ($status < 200 || $status > 299)
        {
            throw new ProviderPermanentFailureException($message);
        }

        $failures = is_array($data['failures'] ?? null) ? $data['failures'] : [];

        if ((int)($data['failed'] ?? 0) > 0 || count($failures) > 0)
        {
            $reasons = json_encode($failures, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            throw new ProviderPermanentFailureException("SMTP2GO refused the email: {$reasons}");
        }

        return is_string($data['email_id'] ?? null) ? $data['email_id'] : '';
    }
}
