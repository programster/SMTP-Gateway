<?php

/*
 * Logging in with Google, against programster/google-sso's fake Google, which signs real ID tokens: the state being
 * kept in the web session (GoogleSsoStateStore), refusing unverified email addresses, and reading two-step
 * verification from the "amr" claim.
 *
 * The fake comes from the package's testing/ folder, which only a source install has, so composer.json installs
 * programster/google-sso from source.
 */

declare(strict_types = 1);

require_once __DIR__ . '/TestSuite.php';

use Programster\GoogleSso\Exceptions\ExceptionMissingCsrfToken;
use Programster\GoogleSso\Exceptions\ExceptionUnverifiedEmail;
use Programster\GoogleSso\GoogleUserData;
use Programster\GoogleSso\GoogleSsoClient;
use Programster\GoogleSso\MfaPolicyEnum;
use Programster\GoogleSso\Testing\FakeGoogleHttpClient;
use Programster\GoogleSso\Testing\TestFixtures;

foreach (['TestSigningKey', 'FakeGoogleHttpClient', 'TestFixtures'] as $fixture)
{
    require_once "/var/www/site/vendor/programster/google-sso/testing/{$fixture}.php";
}

$suite = new TestSuite('Google SSO');
$_SESSION = [];

/**
 * A client whose fake Google issues an ID token with the usual claims, and any others given.
 */
$createClient = function (array $extraClaims) : GoogleSsoClient {
    $fakeGoogle = FakeGoogleHttpClient::issuingIdToken(TestFixtures::validClaims($extraClaims));

    return new GoogleSsoClient(
        clientId: TestFixtures::CLIENT_ID,
        clientSecret: 'secret',
        googleCallbackUrl: 'https://x.example/callback',
        requestFactory: new \GuzzleHttp\Psr7\HttpFactory(),
        responseFactory: new \GuzzleHttp\Psr7\HttpFactory(),
        httpClient: $fakeGoogle,
        csrfTokenStore: new GoogleSsoStateStore(),
        mfaPolicy: MfaPolicyEnum::REPORT
    );
};

/**
 * Get the state a login URL sends Google.
 */
$getState = function (string $loginUrl) : string {
    parse_str(parse_url($loginUrl, PHP_URL_QUERY), $query);
    return $query['state'];
};

/**
 * Log in through the fake Google, whose ID token has the usual claims, and any others given.
 */
$logIn = function (array $extraClaims) use ($createClient, $getState) : GoogleUserData {
    $client = $createClient($extraClaims);
    $state = $getState($client->createGoogleSsoLoginUrl());
    return $client->handleGoogleSsoCallback(['code' => 'code', 'state' => $state]);
};

$client = $createClient([]);
$state = $getState($client->createGoogleSsoLoginUrl());
$suite->check('the state is kept in the web session', WebSession::getGoogleSsoState() === $state);
$user = $client->handleGoogleSsoCallback(['code' => 'code', 'state' => $state]);
$suite->check('the callback is accepted', $user->getEmail() === 'someone@example.com', $user->getEmail());
$suite->check('the state is used up', WebSession::getGoogleSsoState() === '');

$reuseState = function () use ($client, $state) : void {
    $client->handleGoogleSsoCallback(['code' => 'code', 'state' => $state]);
};

$suite->checkThrows('it can\'t be used again', ExceptionMissingCsrfToken::class, $reuseState);

$logInUnverified = function () use ($logIn) : void {
    $logIn(['email_verified' => false]);
};

$suite->checkThrows('an unverified email address is refused', ExceptionUnverifiedEmail::class, $logInUnverified);
$suite->check('no amr: two-step verification not confirmed', !$logIn([])->hasMultiFactorAuthentication());
$isConfirmed = $logIn(['amr' => ['pwd']])->hasMultiFactorAuthentication();
$suite->check('amr with just a password: not confirmed', !$isConfirmed);
$isConfirmed = $logIn(['amr' => ['pwd', 'mfa']])->hasMultiFactorAuthentication();
$suite->check('amr including mfa: confirmed', $isConfirmed);
$isConfirmed = $logIn(['amr' => ['hwk']])->hasMultiFactorAuthentication();
$suite->check('amr with a hardware key: confirmed', $isConfirmed);

$suite->finish();
