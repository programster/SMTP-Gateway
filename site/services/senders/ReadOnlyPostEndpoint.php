<?php

/*
 * The only API endpoints that JsonApiClient may POST to: ones that just return data, on an API that needs POST even
 * to read (SMTP2GO's). Being an enum, a request that could change something in a provider's account can't be written.
 */

declare(strict_types = 1);


enum ReadOnlyPostEndpoint : string
{
    case SMTP2GO_SENDER_DOMAINS = '/domain/view';
    case SMTP2GO_SINGLE_SENDERS = '/single_sender_emails/view';
    case SMTP2GO_KEY_PERMISSIONS = '/api_keys/permissions';
    case SMTP2GO_WEBHOOKS = '/webhook/view';
}
