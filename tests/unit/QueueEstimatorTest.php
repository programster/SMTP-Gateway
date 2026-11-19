<?php

/*
 * The estimate of how long until a worker starts sending an email, which decides whether the client waits for the
 * outcome. In particular, an email a worker has already claimed must never look minutes away because the rate limits
 * count it as already sent.
 */

declare(strict_types = 1);

require_once __DIR__ . '/TestSuite.php';

$suite = new TestSuite('Queue estimator');

$policy = new EmailTokenPolicy(
    IpAllowList::createFromText('0.0.0.0/0'),
    new AnyAddress(),
    new AnyAddress(),
    new AnyAddress(),
    new RateLimitSet(new RateLimit(RateLimitUnit::RECIPIENTS, 3, 60)),
    Priority::forEmailToken(1)
);

$created = EmailTokenRepository::create('estimator test', $policy);
$key = new AuthenticatedEmailToken($created->getEmailToken(), $policy, new IpAddress('127.0.0.1'), MessageSource::SMTP);
$emailTokenId = DB::escape($created->getEmailToken()->getId());

/**
 * Add an email to 3 recipients, sent with the key, and get its ID.
 */
$insertEmail = function (string $status, bool $isDispatched) use ($emailTokenId) : string {
    $dispatchedAt = $isDispatched ? 'now()' : 'NULL';

    return DB::fetchValue(
        'INSERT INTO "email_message" ("id", "email_token_id", "source", "client_ip", "envelope_sender", "recipients",'
        . ' "recipient_count", "size_bytes", "status", "priority", "dispatched_at") VALUES (uuidv7(),'
        . " {$emailTokenId}, 'smtp', '127.0.0.1', 'a@b.test', '[\"x@y.test\", \"y@y.test\", \"z@y.test\"]', 3, 10,"
        . " '{$status}', 1, {$dispatchedAt}) RETURNING \"id\""
    );
};

// It's being sent: it counts towards the limit of 3 recipients a minute, but mustn't hold itself up.
$claimedId = $insertEmail('sending', true);
$estimate = QueueEstimator::estimateSecondsUntilAttempt($key, $claimedId, 3);
$suite->check('an email a worker has claimed is being sent now', $estimate === 0.0, (string)$estimate);

// Another email to 3, queued behind it, has to wait for the minute to pass.
$queuedId = $insertEmail('queued', false);
$estimate = QueueEstimator::estimateSecondsUntilAttempt($key, $queuedId, 3);
$isWaiting = ($estimate > 50 && $estimate <= 60);
$suite->check('one still queued behind a full limit waits for it', $isWaiting, (string)$estimate);

$cancelSql = "UPDATE \"email_message\" SET \"status\" = 'cancelled' WHERE \"email_token_id\" = {$emailTokenId}";
DB::fetchValue("{$cancelSql} RETURNING 1");
EmailTokenRepository::revoke($created->getEmailToken());

$suite->finish();
