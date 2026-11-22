<?php

/*
 * Limits how many times an IP address can fail to log in: after MAX_FAILURES failed passwords, authenticator codes or
 * backup codes within WINDOW_MINUTES, its logins are refused, for any account, until the window is over. It's per
 * address, never per account, so nobody can lock someone else out of their account by failing to log in to it from
 * elsewhere. An IPv6 address counts as its /64, as one machine usually has all of those.
 *
 * Each attempt is counted as a failure before it's checked, and taken back if it passes, so a burst of attempts sent
 * at once can't all be checked before the count catches up. A login that's completed clears its address's count. A
 * correct password alone doesn't, as someone who has it could otherwise clear the count before each guess at the
 * second factor.
 */

declare(strict_types = 1);


final class LoginThrottle
{
    public const int MAX_FAILURES = 5;
    public const int WINDOW_MINUTES = 15;


    /**
     * Make a login attempt from an address: check a password or code, unless the address has failed too many times
     * recently, in which case it isn't checked at all.
     *
     * @param IpAddress $clientIp - where it's from.
     *
     * @param Closure(): bool $check - checks the password or code, giving whether it's right.
     *
     * @return bool - whether it's right.
     *
     * @throws LoginThrottledException - if the address has failed too many times, so it wasn't checked.
     */
    public static function attempt(IpAddress $clientIp, Closure $check) : bool
    {
        $source = self::getSource($clientIp);
        $window = self::WINDOW_MINUTES * 60;
        $isNewWindow = "\"login_throttle\".\"window_started_at\" <= unix_time() - {$window}";
        $windowStart = "CASE WHEN {$isNewWindow} THEN unix_time() ELSE \"login_throttle\".\"window_started_at\" END";

        $result = ServiceFactory::getDb()->query(
            'INSERT INTO "login_throttle" ("source", "failures", "window_started_at") VALUES ($1, 1, unix_time())'
            . ' ON CONFLICT ("source") DO UPDATE SET'
            . " \"failures\" = CASE WHEN {$isNewWindow} THEN 1 ELSE \"login_throttle\".\"failures\" + 1 END,"
            . " \"window_started_at\" = {$windowStart}"
            . ' RETURNING "failures",'
            . " ceil((\"window_started_at\" + {$window} - unix_time()) / 60.0) AS \"minutes_left\"",
            [$source]
        );

        $row = pg_fetch_assoc($result);
        $failures = (int)$row['failures'];

        if ($failures > self::MAX_FAILURES)
        {
            $minutesLeft = max(1, (int)$row['minutes_left']);

            // Only the first refusal is logged, so a script trying over and over doesn't fill the logs.
            if ($failures === self::MAX_FAILURES + 1)
            {
                ServiceFactory::getLogger(LogCategory::ACCESS)->warning('Logins refused after too many failures', [
                    'client_ip' => $source,
                    'minutes' => $minutesLeft,
                ]);
            }

            throw new LoginThrottledException($minutesLeft);
        }

        $isRight = $check();

        if ($isRight)
        {
            $query = 'UPDATE "login_throttle" SET "failures" = GREATEST("failures" - 1, 0) WHERE "source" = $1';
            ServiceFactory::getDb()->query($query, [$source]);
        }

        return $isRight;
    }


    /**
     * Clear an address's failures, once it's logged in.
     * @param IpAddress $clientIp - the address.
     * @return void
     */
    public static function clear(IpAddress $clientIp) : void
    {
        $query = 'DELETE FROM "login_throttle" WHERE "source" = $1';
        ServiceFactory::getDb()->query($query, [self::getSource($clientIp)]);
    }


    /**
     * Get what an address's failures are counted against: an IPv4 address itself, or an IPv6 address's /64.
     *
     * @param IpAddress $clientIp - the address.
     *
     * @return string - e.g. "203.0.113.5", or "2001:db8:1:2::/64".
     */
    public static function getSource(IpAddress $clientIp) : string
    {
        $packed = $clientIp->getPacked();

        $source = $clientIp->isIpv4()
            ? (string)$clientIp
            : inet_ntop(substr($packed, 0, 8) . str_repeat("\0", 8)) . '/64';

        return $source;
    }
}
