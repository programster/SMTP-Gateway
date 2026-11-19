<?php

/*
 * Where PostgreSQL runs, which decides whether its memory counts against this server's when suggesting the limits.
 * It's worked out from DB_HOST, and the Server resources page says which it decided on, so a wrong guess is easy to
 * spot.
 */

declare(strict_types = 1);


enum DatabaseLocation
{
    // The "db" service in the provided compose file, on this server, whose limits the DB_... variables set.
    case BUNDLED;

    // On this server, but not the bundled one, e.g. installed on the host itself.
    case SAME_SERVER;

    // On another server, so it uses none of this server's memory.
    case EXTERNAL;

    public const string BUNDLED_HOST = 'db';


    /**
     * Work out where PostgreSQL runs from the host the app connects to it at.
     *
     * @param string $host - DB_HOST.
     *
     * @param string $dockerHostIp - the Docker host's address (see ServerResources), or an empty string if it isn't
     * known. A database at that address is on this server.
     *
     * @return DatabaseLocation
     */
    public static function createUsingHost(string $host, string $dockerHostIp) : DatabaseLocation
    {
        $host = strtolower(trim($host));
        $localNames = ['localhost', '::1', 'host.docker.internal'];

        // This only looks the name up if it isn't an address already, and gives the name back if it can't be found.
        $address = ($host === self::BUNDLED_HOST) ? $host : gethostbyname($host);

        $isSameServer = (
               in_array($host, $localNames, true)
            || str_starts_with($address, '127.')
            || ($dockerHostIp !== '' && $address === $dockerHostIp)
        );

        return match (true) {
            $host === self::BUNDLED_HOST => DatabaseLocation::BUNDLED,
            $isSameServer => DatabaseLocation::SAME_SERVER,
            default => DatabaseLocation::EXTERNAL,
        };
    }


    /**
     * Whether its memory counts against this server's.
     * @return bool
     */
    public function isOnThisServer() : bool
    {
        return $this !== DatabaseLocation::EXTERNAL;
    }


    /**
     * Describe it, and what that means for the suggestions, for the Server resources page.
     *
     * @param string $host - DB_HOST.
     *
     * @return string
     */
    public function describe(string $host) : string
    {
        $bundled =
            'PostgreSQL is the bundled "db" service, on this server. Its memory is DB_MEMORY_LIMIT, and the DB_...'
            . ' variables set its limits.';

        $sameServer =
            "PostgreSQL is at {$host}, which is on this server, but isn't the bundled one. Its memory is counted as"
            . ' DB_MEMORY_LIMIT, so set that to what it can use. Its suggested settings go in its own'
            . ' postgresql.conf.';

        $external =
            "PostgreSQL is at {$host}, so it's treated as running on another server. Its memory isn't counted, and"
            . ' only the connections it must accept are suggested.';

        return match ($this) {
            DatabaseLocation::BUNDLED => $bundled,
            DatabaseLocation::SAME_SERVER => $sameServer,
            DatabaseLocation::EXTERNAL => $external,
        };
    }
}
