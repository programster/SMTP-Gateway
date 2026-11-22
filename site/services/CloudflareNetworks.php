<?php

/*
 * Cloudflare's address ranges, which a request from Cloudflare comes from: fetched from the lists Cloudflare publishes,
 * and stored in the database (not settings.yaml, as they're Cloudflare's to change). They're fetched on startup and
 * every few hours (scripts/update-cloudflare-networks.php), and when "behind Cloudflare" is turned on. A fetch that
 * fails, or gets something that doesn't look like the lists, keeps the ones fetched before.
 */

declare(strict_types = 1);

use Programster\PgsqlLib\Query;
use Programster\PgsqlObjects\OrmTransaction;


final class CloudflareNetworks
{
    // Cloudflare's lists of its IPv4 and IPv6 ranges, one range per line.
    public const array SOURCES = ['https://www.cloudflare.com/ips-v4', 'https://www.cloudflare.com/ips-v6'];

    private const int TIMEOUT_SECONDS = 15;


    /**
     * Fetch Cloudflare's ranges, and store them in place of the ones stored now.
     *
     * @return int - how many ranges there are.
     *
     * @throws CloudflareFetchException - if a list can't be fetched, or isn't a list of ranges. The ranges stored
     * before are kept.
     */
    public static function fetchAndStore() : int
    {
        $http = new \GuzzleHttp\Client(['timeout' => self::TIMEOUT_SECONDS, 'http_errors' => false]);
        $networks = [];

        foreach (self::SOURCES as $url)
        {
            try
            {
                $response = $http->get($url);
            }
            catch (\GuzzleHttp\Exception\GuzzleException $e)
            {
                $msg = "Cloudflare's ranges couldn't be fetched from {$url}: {$e->getMessage()}";
                throw new CloudflareFetchException($msg);
            }

            if ($response->getStatusCode() !== 200)
            {
                $msg = "Cloudflare's ranges couldn't be fetched from {$url}: it answered {$response->getStatusCode()}.";
                throw new CloudflareFetchException($msg);
            }

            foreach (self::parseList((string)$response->getBody(), $url) as $network)
            {
                $networks[] = $network;
            }
        }

        self::store(new IpNetworkCollection(...$networks));
        return count($networks);
    }


    /**
     * Read one of Cloudflare's lists: one range per line (blank lines ignored).
     *
     * @param string $text - the list.
     *
     * @param string $source - where it came from, for the error.
     *
     * @return IpNetworkCollection
     *
     * @throws CloudflareFetchException - if it's empty, a line isn't a range, or a range covers every address.
     */
    public static function parseList(string $text, string $source) : IpNetworkCollection
    {
        $networks = [];

        foreach (preg_split('/\R/', trim($text)) as $line)
        {
            $line = trim($line);

            if ($line !== '')
            {
                try
                {
                    $network = new IpNetwork($line);
                }
                catch (InvalidInputException $e)
                {
                    throw new CloudflareFetchException("{$source} isn't a list of ranges: {$e->getMessage()}");
                }

                if ($network->getPrefixLength() === 0)
                {
                    throw new CloudflareFetchException("{$source} has a range covering every address ({$line}).");
                }

                $networks[] = $network;
            }
        }

        if (count($networks) === 0)
        {
            throw new CloudflareFetchException("{$source} has no ranges in it.");
        }

        return new IpNetworkCollection(...$networks);
    }


    /**
     * Load the ranges stored, once per request (or script), as they're checked for every request.
     * @return IpNetworkCollection
     */
    public static function loadNetworks() : IpNetworkCollection
    {
        static $networks = null;

        if ($networks === null)
        {
            $found = [];

            $query = 'SELECT "network"::text AS "network" FROM "cloudflare_network"';

            foreach (pg_fetch_all(ServiceFactory::getDb()->query($query)) as $row)
            {
                $found[] = new IpNetwork($row['network']);
            }

            $networks = new IpNetworkCollection(...$found);
        }

        return $networks;
    }


    public static function loadStatus() : CloudflareNetworksStatus
    {
        $query = 'SELECT count(*) AS "count", max("fetched_at") AS "fetched_at" FROM "cloudflare_network"';
        $row = pg_fetch_all(ServiceFactory::getDb()->query($query))[0];
        return new CloudflareNetworksStatus((int)$row['count'], (string)$row['fetched_at']);
    }


    // Put the ranges in place of the ones stored, all at once, so a request never sees only some of them.
    private static function store(IpNetworkCollection $networks) : void
    {
        $queries = [new Query('DELETE FROM "cloudflare_network"')];
        $insert = 'INSERT INTO "cloudflare_network" ("network", "fetched_at") VALUES ($1, now())';
        $insert .= ' ON CONFLICT DO NOTHING';

        foreach ($networks as $network)
        {
            $queries[] = new Query($insert, [(string)$network]);
        }

        $transaction = new OrmTransaction(ServiceFactory::getDb(), $queries);
        $transaction->commit();
    }
}
