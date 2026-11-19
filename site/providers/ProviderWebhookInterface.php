<?php

/*
 * A provider that can call a webhook on this service to report what happened to each email after it was handed
 * over (delivered, bounced, opened, etc). The webhook is served at /webhooks/<provider type>.
 */

declare(strict_types = 1);

use Psr\Http\Message\ServerRequestInterface;


interface ProviderWebhookInterface
{
    /**
     * Whether the webhook is set up (e.g. the key for checking its signature has been configured). When it isn't,
     * the webhook endpoint responds 404, so that unverifiable events are never recorded.
     *
     * @return bool
     */
    public function isWebhookEnabled() : bool;


    /**
     * Check that a webhook request genuinely came from the provider, and get the events in it.
     *
     * @param string $body - the raw request body.
     *
     * @param ServerRequestInterface $request - the request, for its headers.
     *
     * @return ProviderEventCollection
     *
     * @throws RequestRejectedException - if the request's signature is missing or wrong.
     *
     * @throws InvalidInputException - if the body can't be parsed.
     */
    public function parseWebhook(string $body, ServerRequestInterface $request) : ProviderEventCollection;
}
