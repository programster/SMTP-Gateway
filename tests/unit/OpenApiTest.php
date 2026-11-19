<?php

/*
 * The API's OpenAPI document (served at /openapi.json). programster/swagger checks a document as it's built, so a
 * mistake in it fails here, rather than when someone opens the docs.
 */

declare(strict_types = 1);

require_once __DIR__ . '/TestSuite.php';

$suite = new TestSuite('OpenAPI document');

try
{
    $document = json_decode(OpenApiDocument::create()->toJson(), true, flags: JSON_THROW_ON_ERROR);
    $error = '';
}
catch (Throwable $e)
{
    $document = [];
    $error = $e::class . ': ' . $e->getMessage();
}

$suite->check('it builds', $error === '', $error);
$version = $document['openapi'] ?? '';
$suite->check('it\'s OpenAPI 3.0', str_starts_with($version, '3.0.'), $version);

$expectedPaths = [
    '/email-tokens', '/email-tokens/{id}', '/email-tokens/{id}/stats', '/rate-limits', '/stats', '/messages',
    '/messages/{id}', '/messages/{id}/raw', '/send', '/providers', '/providers/export', '/users', '/users/{id}',
    '/users/{id}/block', '/users/{id}/unblock', '/users/{id}/archive',
];

$paths = array_keys($document['paths'] ?? []);
$suite->check('it has every endpoint', $paths === $expectedPaths, implode(', ', $paths));
$schemes = array_keys($document['components']['securitySchemes'] ?? []);
$suite->check('it has both security schemes', $schemes === ['apiKey', 'emailToken'], implode(', ', $schemes));
$bearer = $document['components']['securitySchemes']['apiKey'] ?? [];
$isOpaque = ($bearer['scheme'] ?? '') === 'bearer' && !isset($bearer['bearerFormat']);
$suite->check('API keys aren\'t described as JWTs', $isOpaque, json_encode($bearer));
$emailTokenScheme = $document['components']['securitySchemes']['emailToken'] ?? [];
$isBearer = ($emailTokenScheme['scheme'] ?? '') === 'bearer' && ($emailTokenScheme['type'] ?? '') === 'http';
$suite->check('email tokens are bearer tokens too, not Basic auth', $isBearer, json_encode($emailTokenScheme));
$sendSecurity = $document['paths']['/send']['post']['security'] ?? [];
$isEmailToken = ($sendSecurity === [['emailToken' => []]]);
$suite->check('sending uses the email token instead', $isEmailToken, json_encode($sendSecurity));
$status = $document['paths']['/messages']['get']['parameters'][1]['schema']['enum'] ?? [];
$expected = array_column(MessageStatus::cases(), 'value');
$suite->check('enums list their values', $status === $expected, json_encode($status));
$createRequest = $document['paths']['/email-tokens']['post']['requestBody'] ?? [];
$createToken = $createRequest['content']['application/json']['schema'] ?? [];
$required = $createToken['required'] ?? [];
$suite->check('creating an email token only requires its name', $required === ['name'], json_encode($required));
$conditionFields = [];

foreach ($createToken['properties']['conditions']['items']['oneOf'] ?? [] as $conditionSchema)
{
    array_push($conditionFields, ...($conditionSchema['properties']['field']['enum'] ?? []));
}

$expected = array_column(ConditionField::cases(), 'value');
$isEveryField = ($conditionFields === $expected);
$suite->check('its conditions can be on each field', $isEveryField, json_encode($conditionFields));
$tokenPath = $document['paths']['/email-tokens/{id}'] ?? [];
$token = $tokenPath['get']['responses']['200']['content']['application/json']['schema'] ?? [];
$tokenRequired = $token['properties']['emailToken']['required'] ?? [];
$isOptional = ($tokenRequired === []);
$suite->check('an email token\'s lists aren\'t marked required in responses', $isOptional, json_encode($tokenRequired));
$idPattern = $tokenPath['parameters'][0]['schema']['pattern'] ?? '';
$suite->check('its ID is described by its pattern', $idPattern === '^et_[0-9a-f]{16}$', $idPattern);
$replaceToken = $tokenPath['put']['requestBody']['content']['application/json']['schema'] ?? [];
$replaceRequired = $replaceToken['required'] ?? [];
$isComplete = ($replaceRequired === ['name', 'conditions', 'rateLimits', 'priority']);
$suite->check('an email token can be replaced, with every field required', $isComplete, json_encode($replaceRequired));
$patchToken = $tokenPath['patch']['requestBody']['content']['application/json']['schema'] ?? [];
$patchRequired = $patchToken['required'] ?? [];
$suite->check('or patched, with nothing required', $patchRequired === [], json_encode($patchRequired));
$patchCondition = $patchToken['properties']['conditions']['items']['oneOf'][0]['properties']['allowed'] ?? [];
$createCondition = $createToken['properties']['conditions']['items']['oneOf'][0]['properties']['allowed'] ?? [];
$isRemovable = (($patchCondition['nullable'] ?? false) === true && ($createCondition['nullable'] ?? false) === false);
$suite->check('where only a patch\'s conditions can be null, to remove them', $isRemovable);
$changeResponses = array_map('strval', array_keys($tokenPath['patch']['responses'] ?? []));
$suite->check('changing a revoked one can be a 409 Conflict', in_array('409', $changeResponses, true));

$isUserPath = function (string $path) : bool {
    return str_starts_with($path, '/users');
};

$userPaths = array_values(array_filter(array_keys($document['paths']), $isUserPath));
$expected = ['/users', '/users/{id}', '/users/{id}/block', '/users/{id}/unblock', '/users/{id}/archive'];
$suite->check('the accounts can be managed', $userPaths === $expected, json_encode($userPaths));
$deleteResponses = array_keys($document['paths']['/users/{id}']['delete']['responses'] ?? []);
$suite->check('deleting one can be a 409 Conflict', in_array('409', array_map('strval', $deleteResponses), true));

$suite->finish();
