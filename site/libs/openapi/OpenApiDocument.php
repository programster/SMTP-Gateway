<?php

/*
 * Builds the OpenAPI 3 document for the API (/api/v1), served at /openapi.json, using programster/swagger.
 */

declare(strict_types = 1);

use Programster\Swagger\Components;
use Programster\Swagger\ContentMap;
use Programster\Swagger\Info;
use Programster\Swagger\License;
use Programster\Swagger\MappedResponse;
use Programster\Swagger\MediaType;
use Programster\Swagger\Method;
use Programster\Swagger\MimeType;
use Programster\Swagger\OpenApi;
use Programster\Swagger\Operation;
use Programster\Swagger\OperationCollection;
use Programster\Swagger\Parameter;
use Programster\Swagger\ParameterCollection;
use Programster\Swagger\ParameterLocation;
use Programster\Swagger\Path;
use Programster\Swagger\PathCollection;
use Programster\Swagger\PathItem;
use Programster\Swagger\RequestBody;
use Programster\Swagger\Response;
use Programster\Swagger\ResponseCode;
use Programster\Swagger\ResponseCollection;
use Programster\Swagger\Schemas\Property;
use Programster\Swagger\Schemas\PropertyMap;
use Programster\Swagger\Schemas\SchemaArray;
use Programster\Swagger\Schemas\SchemaBool;
use Programster\Swagger\Schemas\SchemaCollection;
use Programster\Swagger\Schemas\SchemaInt;
use Programster\Swagger\Schemas\SchemaInterface;
use Programster\Swagger\Schemas\SchemaObject;
use Programster\Swagger\Schemas\SchemaOneOf;
use Programster\Swagger\Schemas\SchemaString;
use Programster\Swagger\Schemas\StringFormat;
use Programster\Swagger\Security\HttpBearer;
use Programster\Swagger\Security\NamedSecurityScheme;
use Programster\Swagger\Security\SecurityRequirementCollection;
use Programster\Swagger\Security\SecuritySchemeMap;
use Programster\Swagger\Server;
use Programster\Swagger\ServerCollection;
use Programster\Swagger\Tag;
use Programster\Swagger\TagCollection;


final class OpenApiDocument
{
    private const string API_KEY_SCHEME = 'apiKey';
    private const string EMAIL_TOKEN_SCHEME = 'emailToken';

    // The fields of ActivityCounts::toArray(), each a count.
    private const array ACTIVITY_FIELDS = [
        'acceptedLastMinute', 'acceptedLastHour', 'acceptedLast24Hours', 'acceptedLast7Days', 'queuedNow',
        'failedLast24Hours', 'rejectedLastHour', 'rejectedLast24Hours', 'rejectedLast7Days', 'deliveredLast7Days',
        'bouncedLast7Days', 'openedLast7Days', 'clickedLast7Days', 'spamReportsLast7Days',
        'rejectedByServiceLast24Hours', 'refusedWhileWaitingLast24Hours', 'failedAfterQueueingLast24Hours',
        'retryingNow',
    ];


    public static function create() : OpenApi
    {
        $description = <<<TEXT
            Manage the email tokens that your services send email with, the rate limits, and the statistics, and send
            email.

            **Authentication.** Every endpoint takes a bearer token (`Authorization: Bearer <token>`). Each is one
            string, created in the web interface, whose first 19 characters are its ID (e.g. `ak_8af651aadbe3ff25`),
            and only works from the IP addresses it was given.
             - Every endpoint except `POST /send` needs an **API key** (starting `ak_`), created under "API keys".
             - `POST /send` needs an **email token** (starting `et_`), created under "Email tokens": the same token a
               service uses as its SMTP password.

            **Errors** are returned with a suitable HTTP status code, and a body of
            `{"error": {"message": "...", "code": 1}}`, where `code` is an application-specific code that tells apart
            errors with the same status: -1 unknown, 1 invalid JSON, 2 invalid input, 3 not found, 4 message too
            large, 5 changed by someone else in the meantime, 6 version (If-Match) missing, 10 missing credentials,
            11 invalid credentials, 12 revoked credentials, 13 IP address not allowed, 14 two-factor authentication
            must be set up first, 15 the password must be changed first, 20 sender not allowed, 21 reply-to not
            allowed, 22 recipient not allowed, 23 more recipients than a rate limit allows, 24 no provider the email
            token can use takes the sender, 30 rejected by the provider, 31 no providers have been set up yet, 40 the
            account isn't archived (archive it before deleting it), 41 the account is archived, 42 the last account
            that can log in can't be blocked or archived.

            **SMTP.** Services can also send email through the SMTP server: port 465 (implicit TLS) or 587
            (STARTTLS), logging in with an email token's ID as the username, and the whole token as the password. The
            reply to each email says
            `250 2.0.0 Ok: sent as <id>` or `250 2.0.0 Ok: queued as <id>`.
            TEXT;

        $info = new Info(
            title: SERVICE_NAME . ' API',
            version: '1.0.0',
            description: $description,
            license: new License('MIT', 'https://opensource.org/licenses/MIT'),
        );

        $apiKeyDescription = 'An API key (starting "ak_"), created in the web interface under "API keys".';
        $emailTokenDescription = 'An email token (starting "et_"), created in the web interface under "Email tokens".';
        $apiKey = new NamedSecurityScheme(self::API_KEY_SCHEME, new HttpBearer(description: $apiKeyDescription));
        $emailTokenBearer = new HttpBearer(description: $emailTokenDescription);
        $emailToken = new NamedSecurityScheme(self::EMAIL_TOKEN_SCHEME, $emailTokenBearer);

        $blockDescription =
            'The account can\'t log in until it\'s unblocked, and is logged out everywhere. The last account that can'
            . ' log in can\'t be blocked (409, code 42).';

        $unblockDescription = 'The account can log in again. An archived account can\'t be unblocked (409, code 41).';

        $archiveDescription =
            'Blocks the account, and removes its email address, password, authenticator app, security keys and backup'
            . ' codes, keeping only its ID, which is all the logs name it by: a delete that\'s good enough for a GDPR'
            . ' erasure request. It can then be restored in the web interface (with its email address entered again),'
            . ' or deleted. The last account that can log in can\'t be archived (409, code 42).';

        $paths = new PathCollection(
            self::createEmailTokensPath(),
            self::createEmailTokenPath(),
            self::createEmailTokenStatsPath(),
            self::createRateLimitsPath(),
            self::createStatsPath(),
            self::createMessagesPath(),
            self::createMessagePath(),
            self::createMessageContentPath(),
            self::createSendPath($emailToken),
            self::createProvidersPath(),
            self::createProvidersExportPath(),
            self::createUsersPath(),
            self::createUserPath(),
            self::createUserActionPath('block', 'Block an account', 'blockUser', $blockDescription),
            self::createUserActionPath('unblock', 'Unblock an account', 'unblockUser', $unblockDescription),
            self::createUserActionPath('archive', 'Archive an account', 'archiveUser', $archiveDescription),
        );

        $tags = new TagCollection(
            new Tag('Email tokens', 'The email tokens that services send email with.'),
            new Tag('Rate limits', 'The global rate limits, across every email token.'),
            new Tag('Statistics', 'How much email is coming through, and what happened to it.'),
            new Tag('Sending', 'Sending email with an email token.'),
            new Tag('Providers', 'The providers email is sent through (in settings.yaml), and how they share it.'),
            new Tag('Users', 'The accounts that can log in to the web interface.'),
        );

        return new OpenApi(
            info: $info,
            paths: $paths,
            servers: new ServerCollection(new Server('/api/v1', 'This server')),
            components: new Components(securitySchemes: new SecuritySchemeMap($apiKey, $emailToken)),
            security: new SecurityRequirementCollection($apiKey->createSecurityRequirement()),
            tags: $tags,
        );
    }


    private static function createEmailTokensPath() : Path
    {
        $listSchema = self::createObject(new Property('emailTokens', new SchemaArray(self::createEmailTokenSchema())));
        $listSuccess = self::createJsonResponse(ResponseCode::OK, 'The email tokens, newest first.', $listSchema);

        $includeRevoked = Parameter::createFromSchema(
            name: 'includeRevoked',
            in: ParameterLocation::QUERY,
            required: false,
            schema: new SchemaBool(default: false),
            description: 'Whether to include revoked email tokens.',
        );

        $list = new Operation(
            method: Method::GET,
            responses: self::createResponses($listSuccess, ResponseCode::UNAUTHORIZED, ResponseCode::FORBIDDEN),
            summary: 'List the email tokens',
            parameters: new ParameterCollection($includeRevoked),
            tags: ['Email tokens'],
            operationId: 'listEmailTokens',
        );

        $nameDescription = 'A name to recognise the email token by, e.g. the service that will use it.';

        $createSchema = self::createObject(
            new Property('name', new SchemaString($nameDescription, maxLength: 255), required: true),
            ...self::createPolicyProperties()
        );

        $tokenDescription =
            'The email token: its ID, then its secret. It\'s the bearer token for POST /send, and the SMTP password.'
            . ' This is the only time it is ever returned.';

        $createdSchema = self::createObject(
            new Property('emailToken', self::createEmailTokenSchema()),
            new Property('token', new SchemaString($tokenDescription)),
        );

        $createdDescription = 'The email token was created.';
        $createSuccess = self::createJsonResponse(ResponseCode::CREATED, $createdDescription, $createdSchema);

        $createDescription =
            'Creates an email token with a new secret. Its `conditions` are optional: only the fields given are'
            . ' restricted, so leave one out to allow anyone (or, for `clientIp`, anywhere, and for `provider`, every'
            . ' provider). Fields it doesn\'t have'
            . ' (e.g. a misspelt `conditons`, which would leave it unrestricted), or that the service sets (such as'
            . ' `id`), are refused.';

        $create = new Operation(
            method: Method::POST,
            responses: self::createResponses(
                $createSuccess,
                ResponseCode::BAD_REQUEST,
                ResponseCode::UNAUTHORIZED,
                ResponseCode::FORBIDDEN
            ),
            summary: 'Create an email token',
            description: $createDescription,
            requestBody: self::createJsonRequestBody($createSchema),
            tags: ['Email tokens'],
            operationId: 'createEmailToken',
        );

        return new Path('/email-tokens', new PathItem(new OperationCollection($list, $create)));
    }


    private static function createEmailTokenPath() : Path
    {
        $keySchema = self::createObject(new Property('emailToken', self::createEmailTokenSchema()));
        $getSuccess = self::createJsonResponse(ResponseCode::OK, 'The email token.', $keySchema);

        $get = new Operation(
            method: Method::GET,
            responses: self::createResponses(
                $getSuccess,
                ResponseCode::UNAUTHORIZED,
                ResponseCode::FORBIDDEN,
                ResponseCode::NOT_FOUND
            ),
            summary: 'Get an email token',
            tags: ['Email tokens'],
            operationId: 'getEmailToken',
        );

        $changedSuccess = self::createJsonResponse(ResponseCode::OK, 'The email token, as it is now.', $keySchema);
        $nameDescription = 'A name to recognise the email token by, e.g. the service that will use it.';

        $replaceConditionsDescription =
            'What the email token is restricted to from now on, one condition per field, each at most once. A field'
            . ' with no condition has no restriction, so an empty list allows anyone, from anywhere.';

        $replaceConditions = self::createConditionsSchema($replaceConditionsDescription, ConditionRemoval::REFUSED);

        $replaceSchema = self::createObject(
            new Property('name', new SchemaString($nameDescription, maxLength: 255), required: true),
            new Property('conditions', $replaceConditions, required: true),
            new Property('rateLimits', self::createRateLimitsSchema(), required: true),
            new Property('priority', self::createPrioritySchema(''), required: true),
        );

        $changeEffect =
            ' Its ID and secret stay the same, so whatever uses it carries on working, with its new restrictions from'
            . ' its next request, or its next SMTP login (a connection that\'s already logged in keeps the ones it'
            . ' logged in with). New rate limits apply to its emails already in the queue too, but a new priority only'
            . ' applies to emails queued from then on. A revoked email token can\'t be changed (409).';

        $replaceDescription =
            'Replaces the email token\'s name, conditions, rate limits and priority. Every one of them must be given'
            . ' (an empty list for no conditions, or no rate limits), so leaving one out can\'t remove its restriction'
            . ' by mistake. Fields it doesn\'t have, or that can\'t be changed (such as `id`), are refused. To change'
            . ' only some of them, use PATCH.' . $changeEffect;

        $replace = new Operation(
            method: Method::PUT,
            responses: self::createResponses(
                $changedSuccess,
                ResponseCode::BAD_REQUEST,
                ResponseCode::UNAUTHORIZED,
                ResponseCode::FORBIDDEN,
                ResponseCode::NOT_FOUND,
                ResponseCode::CONFLICT
            ),
            summary: 'Replace an email token\'s restrictions',
            description: $replaceDescription,
            requestBody: self::createJsonRequestBody($replaceSchema),
            tags: ['Email tokens'],
            operationId: 'replaceEmailToken',
        );

        $patchConditionsDescription =
            'The conditions to change. Each one given replaces the email token\'s condition on that field, and the'
            . ' others are kept as they are. Give a condition "allowed": null to remove it, so that field has no'
            . ' restriction.';

        $patchConditions = self::createConditionsSchema($patchConditionsDescription, ConditionRemoval::ALLOWED);

        $patchSchema = self::createObject(
            new Property('name', new SchemaString($nameDescription, maxLength: 255)),
            new Property('conditions', $patchConditions),
            new Property('rateLimits', self::createRateLimitsSchema()),
            new Property('priority', self::createPrioritySchema('')),
        );

        $patchDescription =
            'Changes only what\'s given, and keeps the rest: `name`, `rateLimits` and `priority` replace the email'
            . ' token\'s, and each condition in `conditions` replaces its condition on that field, so changing the'
            . ' allowed senders keeps the allowed IP addresses, for example. Fields it doesn\'t have, or that can\'t be'
            . ' changed (such as `id`), are refused.' . $changeEffect;

        $patch = new Operation(
            method: Method::PATCH,
            responses: self::createResponses(
                $changedSuccess,
                ResponseCode::BAD_REQUEST,
                ResponseCode::UNAUTHORIZED,
                ResponseCode::FORBIDDEN,
                ResponseCode::NOT_FOUND,
                ResponseCode::CONFLICT
            ),
            summary: 'Change some of an email token\'s restrictions',
            description: $patchDescription,
            requestBody: self::createJsonRequestBody($patchSchema),
            tags: ['Email tokens'],
            operationId: 'patchEmailToken',
        );

        $cancelledDescription = 'How many of the email token\'s emails were still queued, and were cancelled.';

        $revokedSchema = self::createObject(
            new Property('emailToken', self::createEmailTokenSchema()),
            new Property('cancelledQueuedEmails', new SchemaInt($cancelledDescription)),
        );

        $revokeSuccess = self::createJsonResponse(ResponseCode::OK, 'The email token was revoked.', $revokedSchema);

        $revokeDescription =
            'The email token stops working straight away, and any of its emails still in the queue are cancelled. Its'
            . ' history is kept, for the statistics. This can\'t be undone.';

        $revoke = new Operation(
            method: Method::DELETE,
            responses: self::createResponses(
                $revokeSuccess,
                ResponseCode::UNAUTHORIZED,
                ResponseCode::FORBIDDEN,
                ResponseCode::NOT_FOUND
            ),
            summary: 'Revoke an email token',
            description: $revokeDescription,
            tags: ['Email tokens'],
            operationId: 'revokeEmailToken',
        );

        $parameters = self::createEmailTokenIdParameter();
        $operations = new OperationCollection($get, $replace, $patch, $revoke);
        return new Path('/email-tokens/{id}', new PathItem($operations, parameters: $parameters));
    }


    private static function createEmailTokenStatsPath() : Path
    {
        $statsDescription = 'The email token\'s statistics.';
        $success = self::createJsonResponse(ResponseCode::OK, $statsDescription, self::createStatsSchema());

        $get = new Operation(
            method: Method::GET,
            responses: self::createResponses(
                $success,
                ResponseCode::UNAUTHORIZED,
                ResponseCode::FORBIDDEN,
                ResponseCode::NOT_FOUND
            ),
            summary: 'Get an email token\'s statistics',
            tags: ['Statistics'],
            operationId: 'getEmailTokenStats',
        );

        $parameters = self::createEmailTokenIdParameter();
        $operations = new OperationCollection($get);
        return new Path('/email-tokens/{id}/stats', new PathItem($operations, parameters: $parameters));
    }


    private static function createRateLimitsPath() : Path
    {
        $rateLimits = new Property('rateLimits', self::createRateLimitsSchema(), required: true);
        $schema = self::createObject($rateLimits);
        $getSuccess = self::createJsonResponse(ResponseCode::OK, 'The global rate limits.', $schema);

        $get = new Operation(
            method: Method::GET,
            responses: self::createResponses($getSuccess, ResponseCode::UNAUTHORIZED, ResponseCode::FORBIDDEN),
            summary: 'Get the global rate limits',
            tags: ['Rate limits'],
            operationId: 'getRateLimits',
        );

        $replaceSuccess = self::createJsonResponse(ResponseCode::OK, 'The new global rate limits.', $schema);

        $replaceDescription =
            'Replaces every global rate limit. An empty array removes them all. An email that would go over a limit'
            . ' is queued, not refused.';

        $replace = new Operation(
            method: Method::PUT,
            responses: self::createResponses(
                $replaceSuccess,
                ResponseCode::BAD_REQUEST,
                ResponseCode::UNAUTHORIZED,
                ResponseCode::FORBIDDEN
            ),
            summary: 'Replace the global rate limits',
            description: $replaceDescription,
            requestBody: self::createJsonRequestBody($schema),
            tags: ['Rate limits'],
            operationId: 'replaceRateLimits',
        );

        return new Path('/rate-limits', new PathItem(new OperationCollection($get, $replace)));
    }


    private static function createStatsPath() : Path
    {
        $statsSchema = self::createStatsSchema();
        $statsDescription = 'The statistics, across every email token.';
        $success = self::createJsonResponse(ResponseCode::OK, $statsDescription, $statsSchema);

        $get = new Operation(
            method: Method::GET,
            responses: self::createResponses($success, ResponseCode::UNAUTHORIZED, ResponseCode::FORBIDDEN),
            summary: 'Get the statistics',
            tags: ['Statistics'],
            operationId: 'getStats',
        );

        return new Path('/stats', new PathItem(new OperationCollection($get)));
    }


    private static function createMessagesPath() : Path
    {
        $messagesSchema = new SchemaArray(self::createMessageSchema());
        $schema = self::createObject(new Property('messages', $messagesSchema));
        $success = self::createJsonResponse(ResponseCode::OK, 'The emails, newest first.', $schema);
        $emailTokenIdSchema = self::createEmailTokenIdSchema();
        $statusSchema = self::createEnum('Only emails with this status.', ...MessageStatus::cases());
        $limitSchema = new SchemaInt(minimum: 1, maximum: 500, default: 100);

        $keyDescription = 'Only emails sent with this email token.';
        $limitDescription = 'The most to return.';

        $query = ParameterLocation::QUERY;

        $parameters = new ParameterCollection(
            Parameter::createFromSchema('emailTokenId', $query, $emailTokenIdSchema, description: $keyDescription),
            Parameter::createFromSchema('status', $query, $statusSchema),
            Parameter::createFromSchema('limit', $query, $limitSchema, description: $limitDescription),
        );

        $list = new Operation(
            method: Method::GET,
            responses: self::createResponses(
                $success,
                ResponseCode::BAD_REQUEST,
                ResponseCode::UNAUTHORIZED,
                ResponseCode::FORBIDDEN
            ),
            summary: 'List the most recent emails',
            parameters: $parameters,
            tags: ['Statistics'],
            operationId: 'listMessages',
        );

        return new Path('/messages', new PathItem(new OperationCollection($list)));
    }


    private static function createMessagePath() : Path
    {
        $detailDescription = 'Any detail, such as a bounce reason or a clicked link.';

        $eventSchema = self::createObject(
            new Property('type', self::createEnum('What happened.', ...EmailEventType::cases())),
            new Property('recipient', new SchemaString('Which recipient it happened for.', nullable: true)),
            new Property('detail', new SchemaString($detailDescription, nullable: true)),
            new Property('occurredAt', new SchemaString(format: StringFormat::DATE_TIME)),
        );

        $eventsDescription = 'What the provider reported happening to the email, oldest first.';

        $attemptsDescription =
            'Each time it was handed to a provider, oldest first. Each one counts towards that provider\'s rate'
            . ' limits, failed ones included.';

        $attemptSchema = self::createObject(
            new Property('provider', new SchemaString('The provider\'s name, from settings.yaml.')),
            new Property('outcome', self::createEnum('How it ended.', ...AttemptOutcome::cases())),
            new Property('dispatchedAt', new SchemaString(format: StringFormat::DATE_TIME)),
            new Property('finishedAt', new SchemaString(format: StringFormat::DATE_TIME, nullable: true)),
            new Property('error', new SchemaString('Why it failed, if it did.', nullable: true)),
        );

        $schema = self::createObject(
            new Property('message', self::createMessageSchema()),
            new Property('deliveryAttempts', new SchemaArray($attemptSchema, $attemptsDescription)),
            new Property('events', new SchemaArray($eventSchema, $eventsDescription)),
        );

        $success = self::createJsonResponse(ResponseCode::OK, 'The email, and its tracking events.', $schema);

        $get = new Operation(
            method: Method::GET,
            responses: self::createResponses(
                $success,
                ResponseCode::UNAUTHORIZED,
                ResponseCode::FORBIDDEN,
                ResponseCode::NOT_FOUND
            ),
            summary: 'Get an email',
            tags: ['Statistics'],
            operationId: 'getMessage',
        );

        $parameters = self::createIdParameter('The email\'s ID.');
        return new Path('/messages/{id}', new PathItem(new OperationCollection($get), parameters: $parameters));
    }


    private static function createMessageContentPath() : Path
    {
        $description =
            'The email, exactly as it was handed to the provider (message/rfc822). Only available while its content'
            . ' is kept, which is set on the Settings page: by default it\'s deleted once sent. Once its attachments'
            . ' have been removed (also set there), it\'s without them, and has an attachments-removed.txt listing'
            . ' them instead.';

        $content = new ContentMap(new MediaType(MimeType::PLAIN_TEXT, new SchemaString()));

        $responses = new ResponseCollection(
            new MappedResponse(ResponseCode::OK, new Response($description, $content)),
            ...self::createErrorResponses(ResponseCode::UNAUTHORIZED, ResponseCode::FORBIDDEN, ResponseCode::NOT_FOUND)
        );

        $get = new Operation(
            method: Method::GET,
            responses: $responses,
            summary: 'Download an email\'s content',
            tags: ['Statistics'],
            operationId: 'downloadMessage',
        );

        $parameters = self::createIdParameter('The email\'s ID.');
        return new Path('/messages/{id}/raw', new PathItem(new OperationCollection($get), parameters: $parameters));
    }


    private static function createSendPath(NamedSecurityScheme $emailToken) : Path
    {
        $contact = self::createObject(
            new Property('email', new SchemaString(format: StringFormat::EMAIL), required: true),
            new Property('name', new SchemaString('The display name. Optional.')),
        );

        $contentTypeDescription = 'The MIME type. Defaults to application/octet-stream.';

        $attachment = self::createObject(
            new Property('filename', new SchemaString(), required: true),
            new Property('content', new SchemaString('The file\'s content, base64 encoded.'), required: true),
            new Property(
                'contentType',
                new SchemaString($contentTypeDescription, default: 'application/octet-stream')
            ),
        );

        $textDescription = 'The plain text body. A text or html body (or both) is required.';
        $htmlDescription = 'The HTML body. The text body, if given, is its plain text alternative.';

        $request = self::createObject(
            new Property('from', $contact, required: true),
            new Property('replyTo', $contact),
            new Property('to', new SchemaArray($contact, minItems: 1), required: true),
            new Property('cc', new SchemaArray($contact)),
            new Property('bcc', new SchemaArray($contact)),
            new Property('subject', new SchemaString(minLength: 1), required: true),
            new Property('text', new SchemaString($textDescription)),
            new Property('html', new SchemaString($htmlDescription)),
            new Property('attachments', new SchemaArray($attachment)),
        );

        $outcomeDescription = 'Whether the email was sent straight away, or queued.';

        $result = self::createObject(
            new Property('id', new SchemaString('The email\'s ID.', format: StringFormat::UUID)),
            new Property('status', self::createEnum($outcomeDescription, ...SubmissionOutcome::cases())),
        );

        $queuedDescription =
            'The email was accepted, and queued, because a rate limit (or the provider being unavailable) meant it'
            . ' couldn\'t be sent straight away. It will be sent as soon as possible.';

        $sentDescription = 'The email was handed to the provider straight away.';

        $errorResponses = self::createErrorResponses(
            ResponseCode::BAD_REQUEST,
            ResponseCode::UNAUTHORIZED,
            ResponseCode::FORBIDDEN,
            ResponseCode::PAYLOAD_TOO_LARGE,
            ResponseCode::BAD_GATEWAY,
        );

        $responses = new ResponseCollection(
            self::createJsonResponse(ResponseCode::CREATED, $sentDescription, $result),
            self::createJsonResponse(ResponseCode::ACCEPTED, $queuedDescription, $result),
            ...$errorResponses
        );

        $sendDescription =
            'Authenticated with an email token, as a bearer token. The email must keep to the token\'s restrictions:'
            . ' its sender, reply-to and every recipient (to, cc and bcc) must be allowed, and the request must come'
            . ' from an allowed IP address. If the token is restricted to particular providers, it\'s only sent through'
            . ' those of them that are enabled, and is queued while none of them is. Only providers that take its'
            . ' sender (its envelope sender, and the From and Sender headers) are used, and if no provider the token'
            . ' can use takes it, it\'s refused (403, code 24).';

        $send = new Operation(
            method: Method::POST,
            responses: $responses,
            summary: 'Send an email',
            description: $sendDescription,
            requestBody: self::createJsonRequestBody($request),
            tags: ['Sending'],
            operationId: 'send',
            security: new SecurityRequirementCollection($emailToken->createSecurityRequirement()),
        );

        return new Path('/send', new PathItem(new OperationCollection($send)));
    }


    private static function createProvidersPath() : Path
    {
        $types = implode(', ', array_column(ProviderType::cases(), 'value'));

        $configDescription =
            'The same structure as settings.yaml\'s providers section: a "mode" (round_robin, overflow or fallback),'
            . ' and a list of'
            . " \"providers\", each with an \"id\" (a UUID, which email tokens' \"provider\" conditions refer to, made"
            . ' when it\'s left out, or kept from the provider of the same name), a'
            . " \"name\", \"type\" ({$types}), \"enabled\", an optional \"allowed_senders\" (\"domains\" and"
            . ' "addresses" lists: it only takes email from a sender at any of the domains, or with any of the'
            . ' addresses, and takes any sender when it\'s left out), "rate_limits" (each with a'
            . ' "unit", "max" and "period_seconds"), and the settings for its type (see "Types of provider" in the'
            . ' README, e.g. sendgrid: api_key and webhook_public_key). Secrets (passwords, keys and tokens) are'
            . ' returned as "<unchanged>", and kept as they are if sent back that way. Settings can\'t be read from'
            . ' environment variables, so a "${NAME}" reference to one is refused. Unknown settings are refused.';

        $config = new SchemaObject($configDescription);

        $getConfigDescription =
            $configDescription . ' Null before any providers have been set up (on a fresh install), when the version is'
            . ' the one to add the first ones with.';

        $getConfig = new SchemaObject($getConfigDescription, nullable: true);

        $status = self::createObject(
            new Property('name', new SchemaString()),
            new Property('available', new SchemaBool('False while it\'s skipped, as it couldn\'t be connected to.')),
            new Property('unavailableUntil', new SchemaString(format: StringFormat::DATE_TIME, nullable: true)),
            new Property('lastError', new SchemaString(nullable: true)),
        );

        $versionDescription = 'The version of the providers, to send as If-Match when replacing them. Also the ETag.';

        $getSchema = self::createObject(
            new Property('version', new SchemaString($versionDescription)),
            new Property('config', $getConfig),
            new Property('status', new SchemaArray($status)),
        );

        $get = new Operation(
            method: Method::GET,
            responses: self::createResponses(
                self::createJsonResponse(ResponseCode::OK, 'The providers, and whether each is available.', $getSchema),
                ResponseCode::UNAUTHORIZED,
                ResponseCode::FORBIDDEN
            ),
            summary: 'Get the providers',
            tags: ['Providers'],
            operationId: 'getProviders',
        );

        $unlinkedDescription =
            'The email tokens that were restricted to a provider that\'s no longer there (or now has another id),'
            . ' which have lost their link to it. Each stays restricted to its other providers, or to none, so its'
            . ' emails wait in the queue until it\'s given one.';

        $replaceSchema = self::createObject(
            new Property('version', new SchemaString('The new version.')),
            new Property('config', $config),
            new Property('unlinkedEmailTokenIds', new SchemaArray(new SchemaString(), $unlinkedDescription)),
        );

        $ifMatch = Parameter::createFromSchema(
            'If-Match',
            ParameterLocation::HEADER,
            new SchemaString(),
            true,
            'The version from GET /providers. If the providers have changed since, the request is refused (412).'
        );

        $replaceDescription =
            'Replaces the providers (settings.yaml\'s providers section, leaving its other settings as they are), from'
            . ' JSON (the "config" structure) or YAML (with a Content-Type of application/yaml). They\'re checked'
            . ' before they\'re saved, and the queue workers use them from their next email, sending any emails that'
            . ' were queued while there were none. At least one provider must be enabled. A provider given without an'
            . ' "id" keeps the id of the current provider with the same name, so it stays linked to its email tokens.'
            . ' Removing a provider removes email tokens\' links to it.';

        $replace = new Operation(
            method: Method::PUT,
            responses: self::createResponses(
                self::createJsonResponse(ResponseCode::OK, 'The providers were replaced.', $replaceSchema),
                ResponseCode::BAD_REQUEST,
                ResponseCode::UNAUTHORIZED,
                ResponseCode::FORBIDDEN,
                ResponseCode::PRECONDITION_FAILED,
                ResponseCode::PRECONDITION_REQUIRED
            ),
            summary: 'Replace the providers',
            description: $replaceDescription,
            parameters: new ParameterCollection($ifMatch),
            requestBody: self::createJsonRequestBody($config),
            tags: ['Providers'],
            operationId: 'replaceProviders',
        );

        return new Path('/providers', new PathItem(new OperationCollection($get, $replace)));
    }


    private static function createProvidersExportPath() : Path
    {
        $description =
            'The providers (settings.yaml\'s providers section) as YAML, as a backup, with every secret (password,'
            . ' key and token) written out in full, so it mustn\'t be committed to version control or shared. 404,'
            . ' with the error code 31, before any providers have been set up.';

        $response = new Response($description, new ContentMap(new MediaType(MimeType::PLAIN_TEXT, new SchemaString())));

        $export = new Operation(
            method: Method::GET,
            responses: new ResponseCollection(
                new MappedResponse(ResponseCode::OK, $response),
                ...self::createErrorResponses(
                    ResponseCode::UNAUTHORIZED,
                    ResponseCode::FORBIDDEN,
                    ResponseCode::NOT_FOUND
                )
            ),
            summary: 'Export the providers as YAML',
            tags: ['Providers'],
            operationId: 'exportProviders',
        );

        return new Path('/providers/export', new PathItem(new OperationCollection($export)));
    }


    private static function createEmailTokenSchema() : SchemaObject
    {
        $idDescription = 'The email token\'s ID: the first 19 characters of the token, and its SMTP username.';

        return self::createObject(
            new Property('id', self::createEmailTokenIdSchema($idDescription)),
            new Property('name', new SchemaString()),
            new Property('createdAt', new SchemaString(format: StringFormat::DATE_TIME)),
            new Property('lastUsedAt', new SchemaString(format: StringFormat::DATE_TIME, nullable: true)),
            new Property('revokedAt', new SchemaString(format: StringFormat::DATE_TIME, nullable: true)),
            ...self::createPolicyProperties()
        );
    }


    /**
     * Get the properties describing an email token's restrictions, shared by the email token itself and the request
     * to create one: its optional conditions, rate limits and priority.
     *
     * @return PropertyCollection
     */
    private static function createPolicyProperties() : PropertyCollection
    {
        $conditionsDescription =
            'What the email token is restricted to, one condition per field, each at most once. A field with no'
            . ' condition has no restriction, so leave out the whole list to allow anyone, from anywhere. An empty'
            . ' "allowed" list is refused, as it would allow nobody.';

        return new PropertyCollection(
            new Property('conditions', self::createConditionsSchema($conditionsDescription, ConditionRemoval::REFUSED)),
            new Property('rateLimits', self::createRateLimitsSchema()),
            new Property('priority', self::createPrioritySchema(' 1 if left out.'))
        );
    }


    /**
     * Get the schema of an email token's list of conditions.
     *
     * @param string $description - what the list does, in the request it's in.
     *
     * @param ConditionRemoval $removal - whether a condition's "allowed" can be null, to remove it (in a PATCH).
     *
     * @return SchemaArray
     */
    private static function createConditionsSchema(string $description, ConditionRemoval $removal) : SchemaArray
    {
        return new SchemaArray(self::createConditionSchema($removal), $description);
    }


    /**
     * Get the schema of an email token's priority.
     *
     * @param string $default - what it is when it's left out, as a sentence starting with a space, or an empty string
     * if it can't be.
     *
     * @return SchemaInt
     */
    private static function createPrioritySchema(string $default) : SchemaInt
    {
        $priorityDescription =
            'How important the email token\'s emails are in the queue: a higher priority\'s emails are sent before a'
            . " lower one's, though rate limits still apply.{$default} The service's own emails (password reset"
            . ' links) have priority 9999, so always go first.';

        return new SchemaInt(
            $priorityDescription,
            minimum: 1,
            maximum: Priority::MAX_EMAIL_TOKEN_PRIORITY,
            default: Priority::DEFAULT
        );
    }


    /**
     * Get the schema of one condition: the client IP addresses allowed, the addresses allowed in one part of the
     * email, or the providers its emails may go through.
     *
     * @param ConditionRemoval $removal - whether its "allowed" can be null, to remove it (in a PATCH).
     *
     * @return SchemaOneOf
     */
    private static function createConditionSchema(ConditionRemoval $removal) : SchemaOneOf
    {
        $canRemove = ($removal === ConditionRemoval::ALLOWED);
        $orNull = $canRemove ? ' Or null, to remove the condition, so the field has no restriction.' : '';

        $ipFieldDescription = 'The IP address the email token is used from.';
        $ipsDescription = 'The IP addresses or CIDR ranges the email token may be used from, e.g. "10.0.0.0/8".';

        $ips = new SchemaArray(new SchemaString(), $ipsDescription . $orNull, minItems: 1, nullable: $canRemove);

        $ipCondition = self::createObject(
            new Property('field', self::createEnum($ipFieldDescription, ConditionField::CLIENT_IP), required: true),
            new Property('allowed', $ips, required: true),
        );

        $fieldDescription =
            '"sender" for who emails may be from (the envelope sender, and the From and Sender headers), "replyTo"'
            . ' for what the Reply-To header may contain (an email without one is always fine), or "recipient" for'
            . ' who emails may be sent to (to, cc and bcc alike).';

        $addressFields = [ConditionField::SENDER, ConditionField::REPLY_TO, ConditionField::RECIPIENT];

        $typeDescription =
            '"domain" for anyone at exactly that domain (not its subdomains), or "address" for one address.';

        $rule = self::createObject(
            new Property('type', self::createEnum($typeDescription, ...AddressRuleKind::cases()), required: true),
            new Property('value', new SchemaString('The domain (e.g. "example.org") or address.'), required: true),
        );

        $rulesDescription = 'The addresses allowed: an address is allowed if any of these rules allows it.';
        $rules = new SchemaArray($rule, $rulesDescription . $orNull, minItems: 1, nullable: $canRemove);

        $addressCondition = self::createObject(
            new Property('field', self::createEnum($fieldDescription, ...$addressFields), required: true),
            new Property('allowed', $rules, required: true),
        );

        $providerField = 'The providers the email token\'s emails go through.';

        $providersDescription =
            'The ids of the providers the email token\'s emails may be sent through (GET /api/v1/providers lists'
            . ' them). The mode applies to those of them that are enabled, as if they were the only providers, and'
            . ' while none of them is enabled, its emails wait in the queue. Each must be a provider\'s id, enabled or'
            . ' not. An empty list is refused, but a token whose providers have all been removed since shows one, as it'
            . ' stays restricted (to none) rather than being allowed every provider.';

        $providerIds = new SchemaArray(
            new SchemaString(format: StringFormat::UUID),
            $providersDescription . $orNull,
            nullable: $canRemove
        );

        $providerCondition = self::createObject(
            new Property('field', self::createEnum($providerField, ConditionField::PROVIDER), required: true),
            new Property('allowed', $providerIds, required: true),
        );

        return new SchemaOneOf(new SchemaCollection($ipCondition, $addressCondition, $providerCondition));
    }


    private static function createRateLimitsSchema() : SchemaArray
    {
        $periodDescription = 'The length of the sliding window, in seconds.';
        $periodSchema = new SchemaInt($periodDescription, minimum: 1, maximum: RateLimit::MAX_PERIOD_SECONDS);

        $unitDescription =
            '"emails" to limit the number of emails handed to the provider (each is one request, however many'
            . ' recipients it has), or "recipients" to limit the number of recipients (To, Cc and Bcc alike).';

        $maxDescription = 'How many emails, or recipients, may be sent within the period.';

        $limit = self::createObject(
            new Property('unit', self::createEnum($unitDescription, ...RateLimitUnit::cases()), required: true),
            new Property('max', new SchemaInt($maxDescription, minimum: 1), required: true),
            new Property('periodSeconds', $periodSchema, required: true),
        );

        $description =
            'Rate limits that must all allow an email for it to be sent straight away, otherwise it\'s queued. Any'
            . ' mix of email and recipient limits can be given, but no two can have the same unit and period. Leave'
            . ' it empty (the default) for no limit. An email with more recipients than a recipient limit allows is'
            . ' refused (error code 23), as it could never be sent.';

        return new SchemaArray($limit, $description);
    }


    private static function createMessageSchema() : SchemaObject
    {
        $emailTokenIdDescription =
            'The email token it was sent with, or null for the service\'s own emails (e.g. password resets).';
        $statusDescription = 'Where the email is in its journey.';
        $priorityDescription =
            'The priority it was queued with: its email token\'s, or 9999 for the service\'s own emails.';

        $hasContentDescription =
            'Whether its content can be downloaded from /messages/{id}/raw (see the retention settings).';


        $removedDescription =
            'How many attachments have been removed from its stored content (see the retention settings).'
            . ' They\'re listed in attachments-removed.txt, which takes their place.';

        return self::createObject(
            new Property('id', new SchemaString(format: StringFormat::UUID)),
            new Property('emailTokenId', self::createEmailTokenIdSchema($emailTokenIdDescription, true)),
            new Property('source', self::createEnum('How it was sent.', ...MessageSource::cases())),
            new Property('clientIp', new SchemaString()),
            new Property('envelopeSender', new SchemaString(format: StringFormat::EMAIL)),
            new Property('recipients', new SchemaArray(new SchemaString(format: StringFormat::EMAIL))),
            new Property('subject', new SchemaString()),
            new Property('sizeBytes', new SchemaInt()),
            new Property('status', self::createEnum($statusDescription, ...MessageStatus::cases())),
            new Property('wasQueued', new SchemaBool('Whether it had to wait in the queue.')),
            new Property('attempts', new SchemaInt('How many times it has been handed to the provider.')),
            new Property('createdAt', new SchemaString(format: StringFormat::DATE_TIME)),
            new Property('sentAt', new SchemaString(format: StringFormat::DATE_TIME, nullable: true)),
            new Property('provider', new SchemaString(nullable: true)),
            new Property('providerMessageId', new SchemaString(nullable: true)),
            new Property('lastError', new SchemaString(nullable: true)),
            new Property('hasContent', new SchemaBool($hasContentDescription)),
            new Property('removedAttachments', new SchemaInt($removedDescription)),
            new Property('priority', new SchemaInt($priorityDescription)),
        );
    }


    private static function createStatsSchema() : SchemaObject
    {
        $activity = [];

        foreach (self::ACTIVITY_FIELDS as $field)
        {
            $activity[] = new Property($field, new SchemaInt());
        }

        $hourly = self::createObject(
            new Property('hourStart', new SchemaString(format: StringFormat::DATE_TIME)),
            new Property('accepted', new SchemaInt('How many emails were accepted in the hour.')),
            new Property('queued', new SchemaInt('How many of those had to wait in the queue.')),
        );

        return self::createObject(
            new Property('activity', self::createObject(...$activity)),
            new Property('hourly', new SchemaArray($hourly, 'The last 24 hours, oldest first.')),
        );
    }


    private static function createUsersPath() : Path
    {
        $listSchema = self::createObject(new Property('users', new SchemaArray(self::createUserSchema())));
        $description = 'The accounts: the active and blocked ones by email address, then any archived ones.';
        $listSuccess = self::createJsonResponse(ResponseCode::OK, $description, $listSchema);

        $archived = Parameter::createFromSchema(
            name: 'archived',
            in: ParameterLocation::QUERY,
            required: false,
            schema: new SchemaBool(default: false),
            description: 'Whether to include archived accounts.',
        );

        $list = new Operation(
            method: Method::GET,
            responses: self::createResponses($listSuccess, ResponseCode::UNAUTHORIZED, ResponseCode::FORBIDDEN),
            summary: 'List the accounts',
            parameters: new ParameterCollection($archived),
            tags: ['Users'],
            operationId: 'listUsers',
        );

        return new Path('/users', new PathItem(new OperationCollection($list)));
    }


    private static function createUserPath() : Path
    {
        $userSchema = self::createObject(new Property('user', self::createUserSchema()));
        $getSuccess = self::createJsonResponse(ResponseCode::OK, 'The account.', $userSchema);

        $get = new Operation(
            method: Method::GET,
            responses: self::createResponses(
                $getSuccess,
                ResponseCode::UNAUTHORIZED,
                ResponseCode::FORBIDDEN,
                ResponseCode::NOT_FOUND
            ),
            summary: 'Get an account',
            tags: ['Users'],
            operationId: 'getUser',
        );

        $deleteDescription =
            'Deletes an archived account for good, with everything linked to it. Only an archived account can be'
            . ' deleted: archive it first (409, code 40, otherwise). Log entries that name it keep its ID.';

        $deleted = new MappedResponse(ResponseCode::NO_CONTENT, new Response('The account was deleted.'));

        $delete = new Operation(
            method: Method::DELETE,
            responses: self::createResponses(
                $deleted,
                ResponseCode::UNAUTHORIZED,
                ResponseCode::FORBIDDEN,
                ResponseCode::NOT_FOUND,
                ResponseCode::CONFLICT
            ),
            summary: 'Delete an archived account',
            description: $deleteDescription,
            tags: ['Users'],
            operationId: 'deleteUser',
        );

        $parameters = self::createIdParameter('The account\'s ID.');
        return new Path('/users/{id}', new PathItem(new OperationCollection($get, $delete), parameters: $parameters));
    }


    /**
     * Create the path for blocking, unblocking or archiving an account.
     *
     * @param string $action - the last part of its path, e.g. "block".
     *
     * @param string $summary - e.g. "Block an account".
     *
     * @param string $operationId - e.g. "blockUser".
     *
     * @param string $description - what it does, and when it's refused.
     *
     * @return Path
     */
    private static function createUserActionPath(
        string $action,
        string $summary,
        string $operationId,
        string $description
    ) : Path
    {
        $userSchema = self::createObject(new Property('user', self::createUserSchema()));
        $success = self::createJsonResponse(ResponseCode::OK, 'The account, as it now is.', $userSchema);

        $operation = new Operation(
            method: Method::POST,
            responses: self::createResponses(
                $success,
                ResponseCode::UNAUTHORIZED,
                ResponseCode::FORBIDDEN,
                ResponseCode::NOT_FOUND,
                ResponseCode::CONFLICT
            ),
            summary: $summary,
            description: $description,
            tags: ['Users'],
            operationId: $operationId,
        );

        $parameters = self::createIdParameter('The account\'s ID.');
        $item = new PathItem(new OperationCollection($operation), parameters: $parameters);
        return new Path("/users/{id}/{$action}", $item);
    }


    private static function createUserSchema() : SchemaObject
    {
        $statusDescription =
            '"active", "blocked" (can\'t log in until it\'s unblocked), or "archived" (blocked, with its email address'
            . ' and sign-in details removed).';

        return self::createObject(
            new Property('id', new SchemaString(format: StringFormat::UUID)),
            new Property('status', self::createEnum($statusDescription, ...AccountStatus::cases())),
            new Property('email', new SchemaString('What it logs in with. Null when it\'s archived.', nullable: true)),
            new Property('createdAt', new SchemaString(format: StringFormat::DATE_TIME)),
            new Property('statusChangedAt', new SchemaString(format: StringFormat::DATE_TIME, nullable: true)),
            new Property('lastLoginAt', new SchemaString(format: StringFormat::DATE_TIME, nullable: true)),
        );
    }


    private static function createIdParameter(string $description) : ParameterCollection
    {
        $schema = new SchemaString(format: StringFormat::UUID);
        $parameter = Parameter::createFromSchema('id', ParameterLocation::PATH, $schema, description: $description);
        return new ParameterCollection($parameter);
    }


    private static function createEmailTokenIdParameter() : ParameterCollection
    {
        $schema = self::createEmailTokenIdSchema();
        $description = 'The email token\'s ID, e.g. et_8af651aadbe3ff25.';
        $parameter = Parameter::createFromSchema('id', ParameterLocation::PATH, $schema, description: $description);
        return new ParameterCollection($parameter);
    }


    /**
     * Create the schema of an email token's ID: its first 19 characters, e.g. "et_8af651aadbe3ff25".
     *
     * @param string|null $description - optionally, what it is.
     *
     * @param bool $isNullable - whether it can be null.
     *
     * @return SchemaString
     */
    private static function createEmailTokenIdSchema(
        ?string $description = null,
        bool $isNullable = false
    ) : SchemaString
    {
        $pattern = '^' . TokenKind::EMAIL_TOKEN->getPrefix() . '[0-9a-f]{16}$';
        return new SchemaString($description, pattern: $pattern, nullable: $isNullable, example: 'et_8af651aadbe3ff25');
    }


    private static function createObject(Property ...$properties) : SchemaObject
    {
        return new SchemaObject(properties: new PropertyMap(...$properties));
    }


    /**
     * Create a string schema that only allows the values of an enum's cases.
     *
     * @param string $description - what the value means.
     *
     * @param BackedEnum ...$cases - the allowed values.
     *
     * @return SchemaString
     */
    private static function createEnum(string $description, BackedEnum ...$cases) : SchemaString
    {
        return new SchemaString($description, enum: array_column($cases, 'value'));
    }


    private static function createJsonRequestBody(SchemaInterface $schema) : RequestBody
    {
        return new RequestBody(new ContentMap(new MediaType(MimeType::JSON, $schema)), required: true);
    }


    private static function createJsonResponse(
        ResponseCode $code,
        string $description,
        SchemaInterface $schema
    ) : MappedResponse
    {
        $content = new ContentMap(new MediaType(MimeType::JSON, $schema));
        return new MappedResponse($code, new Response($description, $content));
    }


    /**
     * Create an operation's responses: its success response, and the standard error response for each error code.
     *
     * @param MappedResponse $success - the success response.
     *
     * @param ResponseCode ...$errorCodes - the error codes the operation can respond with.
     *
     * @return ResponseCollection
     */
    private static function createResponses(MappedResponse $success, ResponseCode ...$errorCodes) : ResponseCollection
    {
        return new ResponseCollection($success, ...self::createErrorResponses(...$errorCodes));
    }


    /**
     * Create the standard error response for each error code.
     *
     * @param ResponseCode ...$codes - the error codes.
     *
     * @return MappedResponseCollection
     */
    private static function createErrorResponses(ResponseCode ...$codes) : MappedResponseCollection
    {
        $errorDetails = self::createObject(
            new Property('message', new SchemaString('What went wrong, for a person to read.')),
            new Property('code', new SchemaInt('The application\'s code for the error (see the top).')),
        );

        $errorSchema = self::createObject(new Property('error', $errorDetails));

        $forbiddenDescription =
            'The credentials can\'t be used from this IP address, or the email isn\'t allowed by the email token\'s'
            . ' restrictions.';

        $descriptions = [
            ResponseCode::BAD_REQUEST->value => 'The request was invalid.',
            ResponseCode::UNAUTHORIZED->value => 'The credentials were missing, wrong or revoked.',
            ResponseCode::FORBIDDEN->value => $forbiddenDescription,
            ResponseCode::NOT_FOUND->value => 'There is no such record.',
            ResponseCode::PAYLOAD_TOO_LARGE->value => 'The email is larger than the limit.',
            ResponseCode::BAD_GATEWAY->value => 'The provider refused the email.',
            ResponseCode::PRECONDITION_FAILED->value => 'It has been changed since the version given in If-Match.',
            ResponseCode::PRECONDITION_REQUIRED->value => 'An If-Match header is required.',
            ResponseCode::CONFLICT->value => 'It can\'t be done to the account in the state it\'s in (see the code).',
        ];

        $responses = [];

        foreach ($codes as $code)
        {
            $responses[] = self::createJsonResponse($code, $descriptions[$code->value] ?? 'An error.', $errorSchema);
        }

        return new MappedResponseCollection(...$responses);
    }
}
