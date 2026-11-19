<?php

/*
 * Telling a provider's refusal for being over its own rate limit (or quota) from any other refusal (RateLimitReply),
 * and the warning that the rate limits set for it here may be too high, with the most each window's limit could be
 * (ProviderRateLimitWarning::describe()).
 */

declare(strict_types = 1);

require_once __DIR__ . '/TestSuite.php';

$suite = new TestSuite('Rate limit replies');

$rateLimits = [
    ['421', '4.7.0 Rate limit exceeded, try again later'],
    ['450', '4.7.1 Too many messages, slow down'],
    ['451', '4.7.1 Throttled: sending too fast'],
    ['550', '5.7.1 Daily sending quota exceeded'],
    ['421', 'Domain mg.example.org is not allowed to send: Ratelimit reached'],
    ['554', '5.7.0 You have exceeded your monthly limit'],
];

foreach ($rateLimits as [$code, $text])
{
    $suite->check("\"{$code} {$text}\" is a rate limit", RateLimitReply::isRateLimit($code, $text));
}

$others = [
    ['550', '5.1.1 The email account that you tried to reach does not exist'],
    ['452', '4.5.3 Too many recipients'],
    ['451', '4.3.0 Temporary server error, please try again later'],
    ['535', '5.7.8 Authentication failed'],
    ['250', 'Ok: rate limit 100 per hour'],
];

foreach ($others as [$code, $text])
{
    $suite->check("\"{$code} {$text}\" isn't", RateLimitReply::isRateLimit($code, $text) === false);
}

$usage = new ProviderUsageWindowCollection(
    new ProviderUsageWindow('minute', 60, 3, 3),
    new ProviderUsageWindow('hour', 3600, 120, 140),
    new ProviderUsageWindow('day', 86400, 950, 1100),
    new ProviderUsageWindow('30 days', 2592000, 950, 1100)
);

$none = ProviderRateLimitWarning::describe('sendgrid', new RateLimitSet(), $usage);
$each = 'at most 3 emails per minute, 120 emails per hour, 950 emails per day, or 950 emails per 30 days:';
$hasEach = str_contains($none, $each);
$suite->check('with no limits here, it suggests the most each window\'s limit could be', $hasEach, $none);
$suite->check('and says other systems may be using up the limit too', str_contains($none, 'other systems'));

$hourly = new RateLimitSet(new RateLimit(RateLimitUnit::EMAILS, 100, 3600));
$capped = ProviderRateLimitWarning::describe('sendgrid', $hourly, $usage);
$isLeftOut = (!str_contains($capped, '120 emails per hour') && str_contains($capped, '950 emails per day'));
$suite->check('a window already capped at or below what was sent isn\'t suggested again', $isLeftOut, $capped);
$suite->check('and the limits here are named', str_contains($capped, '100 emails per hour'), $capped);

$suite->finish();
