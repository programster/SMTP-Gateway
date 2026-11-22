<?php

/*
 * Times stored as Unix timestamps: the helpers that show them, keeping the fraction of a second through the ORM, and
 * the order of things that happened within the same second, which is broken by ID, so first in stays first out.
 */

declare(strict_types = 1);

require_once __DIR__ . '/TestSuite.php';

$suite = new TestSuite('Unix timestamps');
$db = ServiceFactory::getDb();

// The helpers.
$noon = gmmktime(12, 0, 0, 7, 1, 2026);
$suite->check('an int as ISO 8601', Time::toIso($noon) === '2026-07-01T12:00:00+00:00', Time::toIso($noon));
$almost = $noon + 0.999999;
$suite->check('a float is rounded down', Time::toIso($almost) === '2026-07-01T12:00:00+00:00', Time::toIso($almost));
$suite->check('a float is shown like an int', Time::format($almost) === Time::format($noon), Time::format($almost));
$suite->check('whole days between', Time::getDaysBetween($noon, $noon + (2 * 86400) + 5) === 2);
$suite->check('days since', Time::getDaysSince(time() - (3 * 86400) - 5) === 3);
$suite->check('just now', Time::describeAgo(Time::nowPrecise()) === 'just now');
$suite->check('days ago', Time::describeDaysAgo(Time::now() - (12 * 86400)) === '12 days ago');
$logTime = Time::formatUnixTimestamp((string)$noon . '.1234');
$suite->check('the log table\'s times keep their fraction', str_contains($logTime, '.1234'), $logTime);

// Holding the dispatch lock keeps the queue workers from claiming the emails below.
$db->query('SELECT pg_advisory_lock(727274101)');

$policy = new EmailTokenPolicy(
    new IpAllowList(new IpNetwork('0.0.0.0/0')),
    new AnyAddress(),
    new AnyAddress(),
    new AnyAddress(),
    new AnyProvider(),
    new RateLimitSet(),
    Priority::forEmailToken(9000)
);

$tokenId = EmailTokenRepository::create('unix time test', $policy)->getEmailToken()->getId();
$now = Time::now() - 5;
$prefix = '019a0000-0000-7000-8000-00000000000';

$insertEmail = function (string $id) use ($db, $tokenId, $now) : void {
    $db->query(
        'INSERT INTO "email_message" ("id", "email_token_id", "source", "client_ip", "envelope_sender",'
        . ' "sender_addresses", "recipients", "recipient_count", "size_bytes", "raw_message_base64", "status",'
        . ' "priority", "created_at", "next_attempt_at") VALUES ($1, $2, \'api\', \'127.0.0.1\','
        . " 'a@b.test', '[\"a@b.test\"]', '[\"x@y.test\"]', 1, 1, 'eA==', 'queued', 9000, \$3, \$3)",
        [$id, $tokenId, $now]
    );
};

// Inserted in reverse, so only the ID can put them in order.
$insertEmail("{$prefix}3");
$insertEmail("{$prefix}2");
$insertEmail("{$prefix}1");

$scope = RateLimitScope::forEmailToken($tokenId);
$ahead = QueuedDemand::countAhead("{$prefix}2", Priority::forEmailToken(9000), $scope);
$suite->check('within a second, only the lower ID is ahead', $ahead->getEmails() === 1, (string)$ahead->getEmails());

$claim = new ReflectionMethod(MessageDispatcher::class, 'claimNextEmail');
$claimed = $claim->invoke(null);
$suite->check('within a second, the lowest ID is claimed first', $claimed['id'] === "{$prefix}1", $claimed['id']);

// The other two are cancelled before letting go of the lock, so no worker tries to send them.
$cancel = 'UPDATE "email_message" SET "status" = \'cancelled\' WHERE "id" IN ($1, $2)';
$db->query($cancel, ["{$prefix}2", "{$prefix}3"]);
$db->query('SELECT pg_advisory_unlock(727274101)');

// The fraction of a second survives being loaded and saved.
$precise = 1794511981.123456;
$db->query('UPDATE "email_message" SET "dispatched_at" = $1 WHERE "id" = $2', [$precise, "{$prefix}1"]);
$message = Stats::loadMessage("{$prefix}1");
$suite->check('a double column loads as a float', $message->getDispatchedAt() === $precise);
$message->save();
$query = 'SELECT "dispatched_at"::text FROM "email_message" WHERE "id" = $1';
$stored = pg_fetch_result($db->query($query, ["{$prefix}1"]), 0, 0);
$suite->check('and saves without losing precision', $stored === '1794511981.123456', (string)$stored);

/**
 * Get the IDs of a list's records that are among the ones wanted, in the list's order.
 */
$getIdsIn = function (iterable $records, array $wanted) : array {
    $ids = [];

    foreach ($records as $record)
    {
        if (in_array($record->getId(), $wanted, true))
        {
            $ids[] = $record->getId();
        }
    }

    return $ids;
};

// Email tokens and API keys: newest first, then the higher ID.
$newTokens = ['et_aaaaaaaaaaaaaaa2', 'et_aaaaaaaaaaaaaaa1', 'et_aaaaaaaaaaaaaaa9'];
$newKeys = ['ak_aaaaaaaaaaaaaaa2', 'ak_aaaaaaaaaaaaaaa1', 'ak_aaaaaaaaaaaaaaa9'];

foreach ([['email_token', $newTokens], ['api_key', $newKeys]] as [$table, $ids])
{
    foreach ($ids as $index => $id)
    {
        $createdAt = ($index === 2) ? $now - 10 : $now;

        $insert =
            "INSERT INTO \"{$table}\" (\"id\", \"name\", \"secret_hash\", \"created_at\")"
            . " VALUES (\$1, 'x', \$2, \$3)";

        $db->query($insert, [$id, str_repeat('a', 64), $createdAt]);
    }
}

$tokens = $getIdsIn(EmailTokenRepository::loadAll(), $newTokens);
$suite->check('email tokens: newest first, ties by ID', $tokens === $newTokens, implode(', ', $tokens));
$liveTokens = $getIdsIn(EmailTokenRepository::loadAll(false), $newTokens);
$suite->check('and without the revoked ones', $liveTokens === $newTokens, implode(', ', $liveTokens));
$db->query('UPDATE "email_token" SET "revoked_at" = unix_time() WHERE "id" = $1', [$newTokens[0]]);
$liveTokens = $getIdsIn(EmailTokenRepository::loadAll(false), $newTokens);
$suite->check('a revoked token is left out', $liveTokens === array_slice($newTokens, 1), implode(', ', $liveTokens));
$keys = $getIdsIn(ApiKeyRepository::loadAll(), $newKeys);
$suite->check('API keys: newest first, ties by ID', $keys === $newKeys, implode(', ', $keys));

// Accounts.
$insertUser = function (string $id, ?string $email, string $status, ?int $changedAt) use ($db) : void {
    $query =
        'INSERT INTO "admin_user" ("id", "email", "password_hash", "status", "status_changed_at")'
        . " VALUES (\$1, \$2, 'x', \$3, \$4)";

    $db->query($query, [$id, $email, $status, $changedAt]);
};

$userPrefix = '019a0000-0000-7000-8000-0000000001';
$insertUser("{$userPrefix}01", 'zz-unix@example.test', 'active', null);
$insertUser("{$userPrefix}02", 'aa-unix@example.test', 'blocked', $now);
$insertUser("{$userPrefix}03", null, 'archived', $now);
$insertUser("{$userPrefix}04", null, 'archived', $now);
$insertUser("{$userPrefix}05", null, 'archived', $now - 10);
$insertUser("{$userPrefix}06", null, 'archived', null);
$liveIds = ["{$userPrefix}02", "{$userPrefix}01"];
$live = $getIdsIn(AdminUserRepository::loadLive(), $liveIds);
$suite->check('live accounts: active and blocked, by email', $live === $liveIds, implode(', ', $live));
$archivedIds = ["{$userPrefix}06", "{$userPrefix}04", "{$userPrefix}03", "{$userPrefix}05"];
$archived = $getIdsIn(AdminUserRepository::loadArchived(), $archivedIds);
$suite->check('archived: no time first, newest, ties by ID', $archived === $archivedIds, implode(', ', $archived));
$isOnlyArchived = count($getIdsIn(AdminUserRepository::loadArchived(), $liveIds)) === 0;
$suite->check('and no live accounts among them', $isOnlyArchived);

// Security keys: oldest first, then the lower ID.
$credentialIds = ["{$prefix}5", "{$prefix}6", "{$prefix}4"];

foreach ([["{$prefix}6", $now], ["{$prefix}5", $now], ["{$prefix}4", $now + 1]] as [$id, $createdAt])
{
    $insert =
        'INSERT INTO "admin_webauthn_credential" ("id", "admin_user_id", "name", "credential_id", "public_key_pem",'
        . ' "created_at") VALUES ($1, $2, $3, $4, \'x\', $5)';

    $db->query($insert, [$id, "{$userPrefix}01", "key {$id}", "credential {$id}", $createdAt]);
}

$user = AdminUserRepository::load("{$userPrefix}01");
$credentials = $getIdsIn(SecurityKeyService::loadCredentials($user), $credentialIds);
$suite->check('security keys: oldest first, ties by ID', $credentials === $credentialIds, implode(', ', $credentials));

// Events: oldest first, then the lower ID.
$eventIds = ["{$prefix}5", "{$prefix}6", "{$prefix}4"];

foreach ([["{$prefix}6", $now], ["{$prefix}5", $now], ["{$prefix}4", $now + 1]] as [$id, $occurredAt])
{
    $insert =
        'INSERT INTO "email_event" ("id", "email_message_id", "provider", "event_type", "occurred_at")'
        . " VALUES (\$1, \$2, 'test', 'delivered', \$3)";

    $db->query($insert, [$id, "{$prefix}1", $occurredAt]);
}

$events = $getIdsIn(Stats::loadEvents($message), $eventIds);
$suite->check('events: oldest first, ties by ID', $events === $eventIds, implode(', ', $events));

// Leave nothing behind for the tests after this one.
$db->query('DELETE FROM "email_message" WHERE "email_token_id" = $1', [$tokenId]);
$db->query('DELETE FROM "email_token" WHERE "id" = $1 OR "id" LIKE \'et_aaaaaaaaaaaaaaa%\'', [$tokenId]);
$db->query('DELETE FROM "api_key" WHERE "id" LIKE \'ak_aaaaaaaaaaaaaaa%\'');
$db->query('DELETE FROM "admin_user" WHERE "id"::text LIKE $1', ["{$userPrefix}%"]);

$suite->finish();
