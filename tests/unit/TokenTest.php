<?php

/*
 * API keys and email tokens: their format, reading one a client gave (and refusing one of the other kind, saying so),
 * checking their secrets, the tables' IDs, and creating and checking them through the repositories and the email
 * token authenticator.
 */

declare(strict_types = 1);

require_once __DIR__ . '/TestSuite.php';

$suite = new TestSuite('Tokens');

// The format: the prefix and 16 hex characters (the ID), then the 64-hex-character secret.
$emailToken = Token::generate(TokenKind::EMAIL_TOKEN);
$apiKey = Token::generate(TokenKind::API_KEY);
$isFormed = preg_match('/^et_[0-9a-f]{80}$/', $emailToken->getPlaintext()) === 1;
$suite->check('an email token is et_ and 80 hex characters', $isFormed, $emailToken->getPlaintext());
$isFormed = preg_match('/^ak_[0-9a-f]{80}$/', $apiKey->getPlaintext()) === 1;
$suite->check('an API key is ak_ and 80 hex characters', $isFormed);
$suite->check('83 characters in all', strlen($emailToken->getPlaintext()) === Token::LENGTH && Token::LENGTH === 83);
$isStart = ($emailToken->getId() === substr($emailToken->getPlaintext(), 0, 19));
$suite->check('its ID is its first 19 characters', $isStart);
$suite->check('which is an email token ID', TokenKind::EMAIL_TOKEN->isId($emailToken->getId()));
$suite->check('but not an API key ID', TokenKind::API_KEY->isId($emailToken->getId()) === false);
$another = Token::generate(TokenKind::EMAIL_TOKEN);
$suite->check('every token is different', $another->getPlaintext() !== $emailToken->getPlaintext());
$suite->check('as is its ID', $another->getId() !== $emailToken->getId());

// Reading one a client gave.
$read = Token::createFromString(" {$emailToken->getPlaintext()}\n", TokenKind::EMAIL_TOKEN);
$suite->check('a token is read, ignoring whitespace', $read->getPlaintext() === $emailToken->getPlaintext());
$suite->check('its secret matches its hash', $read->matchesHash($emailToken->getSecretHash()));
$suite->check('but not another\'s', $read->matchesHash($another->getSecretHash()) === false);
$hashesSecret = ($emailToken->getSecretHash() === hash('sha256', substr($emailToken->getPlaintext(), 19)));
$suite->check('the hash is of the secret alone', $hashesSecret);

$readAsApiKey = function () use ($emailToken) : void {
    Token::createFromString($emailToken->getPlaintext(), TokenKind::API_KEY);
};

$readAsEmailToken = function () use ($apiKey) : void {
    Token::createFromString($apiKey->getPlaintext(), TokenKind::EMAIL_TOKEN);
};

$suite->checkThrows('an email token is refused as an API key', InvalidTokenException::class, $readAsApiKey);
$suite->checkThrows('and an API key as an email token', InvalidTokenException::class, $readAsEmailToken);

$getMessage = function (string $token, TokenKind $kind) : string {
    $message = '';

    try
    {
        Token::createFromString($token, $kind);
    }
    catch (InvalidTokenException $e)
    {
        $message = $e->getMessage();
    }

    return $message;
};

$message = $getMessage($apiKey->getPlaintext(), TokenKind::EMAIL_TOKEN);
$suite->check('which says it\'s an API key', str_starts_with($message, 'That\'s an API key'), $message);
$message = $getMessage($emailToken->getPlaintext(), TokenKind::API_KEY);
$suite->check('and the other way round', str_starts_with($message, 'That\'s an email token'), $message);

$malformed = [
    'too short' => substr($emailToken->getPlaintext(), 0, -1),
    'too long' => $emailToken->getPlaintext() . '0',
    'in upper case' => strtoupper($emailToken->getPlaintext()),
    'not hex' => 'et_' . str_repeat('g', 80),
    'an unknown prefix' => 'xx_' . str_repeat('0', 80),
    'empty' => '',
    'an old 64-character secret' => str_repeat('a', 64),
];

foreach ($malformed as $description => $token)
{
    $message = $getMessage($token, TokenKind::EMAIL_TOKEN);
    $suite->check("a token that's {$description} is refused", $message === 'That isn\'t an email token.', $message);
}

// The tables' IDs.
$normalized = EmailTokenTable::getInstance()->normalizeId(strtoupper($emailToken->getId()));
$suite->check('an email token ID is lower-cased', $normalized === $emailToken->getId());

$normalizeApiKeyId = function () use ($emailToken) : void {
    ApiKeyTable::getInstance()->normalizeId($emailToken->getId());
};

$normalizeUuid = function () : void {
    EmailTokenTable::getInstance()->normalizeId('0190a6f4-2b6e-7cc2-9d3e-1a2b3c4d5e6f');
};

$invalidId = \Programster\PgsqlObjects\Exceptions\ExceptionInvalidId::class;
$suite->checkThrows('an email token ID isn\'t an API key ID', $invalidId, $normalizeApiKeyId);
$suite->checkThrows('nor is a UUID an email token ID', $invalidId, $normalizeUuid);
$generatedId = ApiKeyTable::getInstance()->generateId();
$suite->check('a generated ID is the table\'s', TokenKind::API_KEY->isId($generatedId));

// Creating them, and checking them.
$policy = new EmailTokenPolicy(
    IpAllowList::createFromText('0.0.0.0/0'),
    new AnyAddress(),
    new AnyAddress(),
    new AnyAddress(),
    new AnyProvider(),
    new RateLimitSet(),
    Priority::forEmailToken(1)
);

$created = EmailTokenRepository::create('token test', $policy);
$token = $created->getToken();
$record = $created->getEmailToken();
$suite->check('a created email token\'s ID is its record\'s', $record->getId() === $token->getId());
$suite->check('and only its secret\'s hash is stored', $record->getSecretHash() === $token->getSecretHash());
$ip = new IpAddress('127.0.0.1');
$authenticated = EmailTokenAuthenticator::authenticate($token->getPlaintext(), $ip, MessageSource::API);
$suite->check('it authenticates', $authenticated->getEmailToken()->getId() === $token->getId());
$smtp = EmailTokenAuthenticator::authenticateSmtpLogin($token->getId(), $token->getPlaintext(), $ip);
$suite->check('and logs in to SMTP with its ID and the token', $smtp->getEmailToken()->getId() === $token->getId());

$getRejection = function (Closure $authenticate) : string {
    $message = '';

    try
    {
        $authenticate();
    }
    catch (RequestRejectedException $e)
    {
        $message = $e->getMessage();
    }

    return $message;
};

$wrongSecret = function () use ($token, $ip) : void {
    EmailTokenAuthenticator::authenticate($token->getId() . str_repeat('0', 64), $ip, MessageSource::API);
};

$wrongUsername = function () use ($token, $ip, $another) : void {
    EmailTokenAuthenticator::authenticateSmtpLogin($another->getId(), $token->getPlaintext(), $ip);
};

$createdApiKey = ApiKeyRepository::create('token test', IpAllowList::createFromText('0.0.0.0/0'));

$apiKeyGiven = function () use ($createdApiKey, $ip) : void {
    EmailTokenAuthenticator::authenticate($createdApiKey->getToken()->getPlaintext(), $ip, MessageSource::API);
};

$message = $getRejection($wrongSecret);
$suite->check('a wrong secret is refused', $message === 'Invalid credentials.', $message);
$message = $getRejection($wrongUsername);
$suite->check('an SMTP username that isn\'t the token\'s ID is refused', str_contains($message, 'username'), $message);
$message = $getRejection($apiKeyGiven);
$isSaid = str_starts_with($message, 'That\'s an API key');
$suite->check('an API key given as an email token is refused, saying so', $isSaid, $message);

$found = ApiKeyRepository::findByToken($createdApiKey->getToken());
$suite->check('an API key is found by its token', $found->getId() === $createdApiKey->getApiKey()->getId());

$findWrongSecret = function () use ($createdApiKey) : void {
    $wrong = Token::createFromString($createdApiKey->getToken()->getId() . str_repeat('0', 64), TokenKind::API_KEY);
    ApiKeyRepository::findByToken($wrong);
};

$suite->checkThrows('but not with the wrong secret', NotFoundException::class, $findWrongSecret);

EmailTokenRepository::revoke($created->getEmailToken());
ApiKeyRepository::revoke($createdApiKey->getApiKey());

$revokedGiven = function () use ($token, $ip) : void {
    EmailTokenAuthenticator::authenticate($token->getPlaintext(), $ip, MessageSource::API);
};

$message = $getRejection($revokedGiven);
$suite->check('a revoked email token is refused', $message === 'This email token has been revoked.', $message);

$suite->finish();
