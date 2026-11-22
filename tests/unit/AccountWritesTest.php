<?php

/*
 * Changes to an account made from a copy loaded before someone else changed it, as two requests at the same moment
 * would: they must not undo a "log out everywhere", and a TOTP code must only ever be accepted once.
 */

declare(strict_types = 1);

require_once __DIR__ . '/TestSuite.php';

use OTPHP\TOTP;
use Programster\PgsqlLib\PgsqlLib;
use Programster\PgsqlLib\UuidVersion;
use Programster\PgsqlObjects\CacheMode;

$suite = new TestSuite('Account writes');
$password = 'correct horse battery staple';
$weakHash = new PasswordHash(password_hash($password, PASSWORD_BCRYPT, ['cost' => 4]));
$user = AdminUserRepository::createWithPasswordHash(new EmailAddress('writes@example.com'), $weakHash, false);
$id = $user->getId();

/**
 * Log the account out everywhere, returning the session version that has to survive.
 */
$logOutEverywhere = function () use ($id) : int {
    $query = 'UPDATE "admin_user" SET "session_version" = "session_version" + 1 WHERE "id" = $1'
        . ' RETURNING "session_version"';

    return (int)pg_fetch_result(ServiceFactory::getDb()->query($query, [$id]), 0, 0);
};

$createCode = function (string $secret) : string {
    return TOTP::createFromSecret($secret, new SystemClock())->now();
};

$stale = AdminUserRepository::load($id);
$expected = $logOutEverywhere();
AdminUserRepository::changeEmail($stale, new EmailAddress('changed@example.com'));
$current = AdminUserRepository::load($id);
$suite->check('changing the email keeps a logout', $current->getSessionVersion() === $expected);
$suite->check('and changes the email', $current->getEmail() === 'changed@example.com', $current->getEmail());

$stale = AdminUserRepository::load($id);
$expected = $logOutEverywhere();
$suite->check('the password is accepted', AdminUserRepository::verifyPassword($stale, $password));
$current = AdminUserRepository::load($id);
$suite->check('upgrading its hash keeps a logout', $current->getSessionVersion() === $expected);
$isUpgraded = password_needs_rehash($current->getPasswordHash(), PASSWORD_DEFAULT) === false;
$suite->check('and upgrades the hash', $isUpgraded);
$suite->check('which still matches', password_verify($password, $current->getPasswordHash()));

$secret = TotpService::generateSecret();
$setSecret = 'UPDATE "admin_user" SET "totp_secret" = $1, "totp_last_used_step" = NULL WHERE "id" = $2';
ServiceFactory::getDb()->query($setSecret, [$secret, $id]);
$first = AdminUserRepository::load($id);
$second = AdminUserRepository::load($id);
$code = $createCode($secret);
$suite->check('a code is accepted', TotpService::verify($first, $code));
$suite->check('but not by a second request at the same moment', TotpService::verify($second, $code) === false);
$suite->check('nor again afterwards', TotpService::verify(AdminUserRepository::load($id), $code) === false);

ServiceFactory::getDb()->query($setSecret, [$secret, $id]);
$stale = AdminUserRepository::load($id);
$expected = $logOutEverywhere();
TotpService::disable($stale);
$current = AdminUserRepository::load($id);
$suite->check('turning off TOTP keeps a logout', $current->getSessionVersion() === $expected);
$suite->check('a code isn\'t accepted once TOTP is off', TotpService::verify($stale, $createCode($secret)) === false);

$stale = AdminUserRepository::load($id);
$expected = $logOutEverywhere();
$enabled = TotpService::enable($stale, $secret, $createCode($secret));
$current = AdminUserRepository::load($id);
$suite->check('turning on TOTP keeps a logout', $current->getSessionVersion() === $expected);
$suite->check('and returns the account with it on', $enabled->hasTotp());

/**
 * Add a security key with the given signature counter, returning its ID.
 */
$addKey = function (string $credentialId, int $counter) use ($id) : string {
    $keyId = PgsqlLib::generateUuid(UuidVersion::V4);

    $query = 'INSERT INTO "admin_webauthn_credential"'
        . ' ("id", "admin_user_id", "name", "credential_id", "public_key_pem", "signature_counter")'
        . ' VALUES ($1, $2, \'Key\', $3, \'pem\', $4)';

    ServiceFactory::getDb()->query($query, [$keyId, $id, $credentialId, $counter]);
    return $keyId;
};

$loadKey = function (string $keyId) : AdminWebauthnCredential {
    return AdminWebauthnCredentialTable::getInstance()->loadWhereAnd(['id' => $keyId], CacheMode::IGNORE_CACHE)[0];
};

$keyId = $addKey('counting-key', 5);
$first = $loadKey($keyId);
$second = $loadKey($keyId);
$suite->check('a security key\'s use is recorded', SecurityKeyService::recordUse($first, 6));
$suite->check('but not the same counter at the same moment', SecurityKeyService::recordUse($second, 6) === false);
$suite->check('nor an older counter', SecurityKeyService::recordUse($second, 4) === false);
$stored = $loadKey($keyId)->getSignatureCounter();
$suite->check('and the counter never moves backwards', $stored === 6, (string)$stored);

$keyId = $addKey('key-without-a-counter', 0);
$suite->check('a key without a counter can be used', SecurityKeyService::recordUse($loadKey($keyId), 0));
$suite->check('more than once', SecurityKeyService::recordUse($loadKey($keyId), 0));

ServiceFactory::getDb()->query('DELETE FROM "admin_user" WHERE "id" = $1', [$id]);
$suite->finish();
