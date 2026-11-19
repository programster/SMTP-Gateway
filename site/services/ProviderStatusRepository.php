<?php

/*
 * Remembers which providers couldn't be connected to recently, so they're skipped for a while (UNAVAILABLE_SECONDS),
 * rather than every email waiting for the connection to time out before moving on, and which refused an email as over
 * their own rate limit, which are skipped for a while too (RATE_LIMITED_SECONDS), so their limit has time to reset.
 * Once the time is up, the next email tries the provider again, and if that works, it's available again.
 */

declare(strict_types = 1);


final class ProviderStatusRepository
{
    public const int UNAVAILABLE_SECONDS = 60;
    public const int RATE_LIMITED_SECONDS = 60;


    public static function markUnavailable(string $providerName, string $error) : void
    {
        self::pause($providerName, $error, self::UNAVAILABLE_SECONDS);
        $logger = ServiceFactory::getLogger(LogCategory::EMAIL);
        $logger->warning("Provider {$providerName} is unavailable", ['error' => $error]);
    }


    /**
     * Skip a provider for RATE_LIMITED_SECONDS, as it refused an email as over its own rate limit (see
     * ProviderRateLimitWarning, which says so in the logs).
     *
     * @param string $providerName - the provider.
     *
     * @param string $error - its reply.
     *
     * @return void
     */
    public static function markRateLimited(string $providerName, string $error) : void
    {
        self::pause($providerName, "Over its rate limit: {$error}", self::RATE_LIMITED_SECONDS);
    }



    public static function markAvailable(string $providerName) : void
    {
        $name = DB::escape($providerName);
        ServiceFactory::getDb()->query("DELETE FROM \"provider_status\" WHERE \"provider\" = {$name}");
    }


    /**
     * Get the names of the providers that are being skipped right now.
     *
     * @return string[]
     */
    public static function loadUnavailableNames() : array
    {
        $rows = DB::fetchRows('SELECT "provider" FROM "provider_status" WHERE "unavailable_until" > now()');
        return array_column($rows, 'provider');
    }


    /**
     * Get why each unavailable provider is being skipped, for showing in the web interface.
     *
     * @return array - provider name to an array of "unavailable_until" and "last_error".
     */
    public static function loadUnavailableDetails() : array
    {
        $details = [];
        $query =
            'SELECT "provider", "unavailable_until", "last_error" FROM "provider_status"'
            . ' WHERE "unavailable_until" > now()';


        foreach (DB::fetchRows($query) as $row)
        {
            $details[$row['provider']] = [
                'unavailable_until' => $row['unavailable_until'],
                'last_error' => $row['last_error'],
            ];
        }

        return $details;
    }


    // Skip a provider for a while, keeping why.
    private static function pause(string $providerName, string $error, int $seconds) : void
    {
        $id = DB::escape((string)ProviderStatusTable::getInstance()->generateId());
        $name = DB::escape($providerName);
        $escapedError = DB::escape(mb_substr($error, 0, 1000));

        ServiceFactory::getDb()->query(
            'INSERT INTO "provider_status" ("id", "provider", "unavailable_until", "last_error", "updated_at")'
            . " VALUES ({$id}, {$name}, now() + make_interval(secs => {$seconds}), {$escapedError}, now())"
            . ' ON CONFLICT ("provider") DO UPDATE SET "unavailable_until" = EXCLUDED."unavailable_until",'
            . ' "last_error" = EXCLUDED."last_error", "updated_at" = now()'
        );
    }
}
