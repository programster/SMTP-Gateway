<?php

/*
 * Working out a web request's real IP address (ClientIpResolver::resolveFrom()): X-Forwarded-For is only believed from
 * a trusted proxy, and CF-Connecting-IP only from one of Cloudflare's addresses, whether Cloudflare sends requests
 * straight here or through your proxies. Also reading Cloudflare's lists of its ranges (CloudflareNetworks).
 */

declare(strict_types = 1);

require_once __DIR__ . '/TestSuite.php';

$suite = new TestSuite('Client IP');
$proxies = new IpNetworkCollection(new IpNetwork('10.0.0.0/8'));
$cloudflare = new IpNetworkCollection(new IpNetwork('173.245.48.0/20'), new IpNetwork('2400:cb00::/32'));
$none = new IpNetworkCollection();

$resolve = function (string $connection, string $forwardedFor, string $cfHeader, bool $isBehindCloudflare) use (
    $proxies,
    $cloudflare,
    $none
) : string {
    $networks = $isBehindCloudflare ? $cloudflare : $none;
    $address = ClientIpResolver::resolveFrom(new IpAddress($connection), $forwardedFor, $cfHeader, $proxies, $networks);
    return (string)$address;
};

// Without Cloudflare: X-Forwarded-For only from a trusted proxy.
$suite->check('a direct request is its own address', $resolve('203.0.113.9', '', '', false) === '203.0.113.9');
$isIgnored = ($resolve('203.0.113.9', '198.51.100.1', '', false) === '203.0.113.9');
$suite->check('X-Forwarded-For from anyone else is ignored', $isIgnored);
$isBelieved = ($resolve('10.0.0.5', '198.51.100.1', '', false) === '198.51.100.1');
$suite->check('X-Forwarded-For from a trusted proxy is believed', $isBelieved);
$isWalked = ($resolve('10.0.0.5', '192.0.2.66, 198.51.100.1, 10.0.0.7', '', false) === '198.51.100.1');
$suite->check('only as far back as the first address that isn\'t a proxy', $isWalked);
$isStopped = ($resolve('10.0.0.5', 'not-an-address', '', false) === '10.0.0.5');
$suite->check('a hop that isn\'t an address stops the walk', $isStopped);
$isIgnored = ($resolve('173.245.48.1', '', '198.51.100.1', false) === '173.245.48.1');
$suite->check('CF-Connecting-IP is ignored when not behind Cloudflare', $isIgnored);

// Behind Cloudflare, straight here.
$isBelieved = ($resolve('173.245.48.1', '198.51.100.1', '198.51.100.1', true) === '198.51.100.1');
$suite->check('behind Cloudflare, CF-Connecting-IP from Cloudflare is believed', $isBelieved);
$isBelieved = ($resolve('2400:cb00::1', '', '2001:db8::5', true) === '2001:db8::5');
$suite->check('over IPv6 too', $isBelieved);
$isIgnored = ($resolve('203.0.113.9', '', '198.51.100.1', true) === '203.0.113.9');
$suite->check('but not from anyone else, who could make it up', $isIgnored);
$isKept = ($resolve('173.245.48.1', '', 'nonsense', true) === '173.245.48.1');
$suite->check('and not when it isn\'t an address', $isKept);

// Behind Cloudflare, then your own proxies.
$isBelieved = ($resolve('10.0.0.5', '198.51.100.1, 173.245.48.1', '198.51.100.1', true) === '198.51.100.1');
$suite->check('through your proxy, Cloudflare\'s CF-Connecting-IP is still believed', $isBelieved);
$isIgnored = ($resolve('10.0.0.5', '203.0.113.9', '198.51.100.1', true) === '203.0.113.9');
$suite->check('but not when what reached your proxy wasn\'t Cloudflare', $isIgnored);
$isIgnored = ($resolve('10.0.0.5', '198.51.100.1, 173.245.48.1', '198.51.100.1', false) === '173.245.48.1');
$suite->check('and not when not behind Cloudflare, even through it', $isIgnored);

// Reading Cloudflare's lists.
$list = CloudflareNetworks::parseList("173.245.48.0/20\n103.21.244.0/22\n\n", 'ips-v4');
$suite->check('a list of Cloudflare\'s ranges is read, ignoring blank lines', count($list) === 2);

$badLists = [
    'a list with a line that isn\'t a range' => "173.245.48.0/20\n<html>",
    'an empty list' => "\n",
    'a range covering every address' => '0.0.0.0/0',
];

foreach ($badLists as $description => $text)
{
    $parse = function () use ($text) : void {
        CloudflareNetworks::parseList($text, 'ips-v4');
    };

    $suite->checkThrows("{$description} is refused", CloudflareFetchException::class, $parse);
}

$suite->finish();
