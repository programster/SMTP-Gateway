<?php

/*
 * Thrown when a provider refuses an email because its own rate limit (or quota) has been reached (see
 * RateLimitReply), so another provider is tried, the provider is paused for a while, and the rate limits set for it
 * here are checked. It's a kind of temporary failure, as the same email will go through once there's room.
 */

declare(strict_types = 1);


class ProviderRateLimitedException extends ProviderTemporaryFailureException
{
}
