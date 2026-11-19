<?php

/*
 * Fetches Cloudflare's address ranges, and stores them in place of the ones stored before (see CloudflareNetworks),
 * when the site is behind Cloudflare (proxies.behind_cloudflare in settings.yaml). Run on startup, in case the
 * service has been down a while, and every few hours from cron. If they can't be fetched, the ones stored before are
 * kept, and it exits with 1, so the failure shows in its log.
 */

declare(strict_types = 1);

require_once(__DIR__ . '/../bootstrap.php');


function main() : void
{
    if (Settings::get()->getProxies()->isBehindCloudflare() === false)
    {
        print 'Not behind Cloudflare (proxies.behind_cloudflare), so its ranges aren\'t fetched.' . PHP_EOL;
    }
    else
    {
        try
        {
            $count = CloudflareNetworks::fetchAndStore();
            print "Fetched Cloudflare's {$count} address ranges." . PHP_EOL;
        }
        catch (CloudflareFetchException $e)
        {
            $logger = ServiceFactory::getLogger(LogCategory::SYSTEM);

            $logger->warning('Cloudflare\'s address ranges couldn\'t be fetched', [
                'error' => $e->getMessage(),
            ]);

            fwrite(STDERR, $e->getMessage() . ' The ranges fetched before are kept.' . PHP_EOL);
            exit(1);
        }
    }
}


main();
