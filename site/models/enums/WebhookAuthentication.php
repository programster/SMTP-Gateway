<?php

/*
 * How a provider's webhook requests prove they're from it, using the provider's webhook_secret (see
 * SecretWebhookChecks).
 */

declare(strict_types = 1);


enum WebhookAuthentication
{
    // The secret is the password of the request's HTTP Basic authentication (see WebhookBasicAuth).
    case PASSWORD;

    // The provider signs each request with the secret (e.g. an HMAC of its body), which it generates.
    case SIGNATURE;
}
