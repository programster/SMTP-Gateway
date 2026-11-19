<?php

/*
 * Fakes for the unit tests of things that use DNS and providers' APIs, which answer with made-up records and
 * responses, so the tests never need the network.
 */

declare(strict_types = 1);

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;


/*
 * DNS with made-up records. A name listed in $failing fails to look up.
 */
final class FakeDnsResolver implements DnsResolverInterface
{
    private array $m_txt;
    private array $m_mailHosts;
    private array $m_failing;
    private array $m_addresses;
    private array $m_mx;
    private array $m_ptr;


    /**
     * @param array<string, list<string>> $txt - the TXT records, by name.
     *
     * @param list<string> $mailHosts - the domains that can receive email.
     *
     * @param list<string> $failing - the names whose lookups fail.
     *
     * @param array<string, list<string>> $addresses - the A and AAAA records, by name.
     *
     * @param array<string, list<string>> $mx - the MX hosts, by domain.
     *
     * @param array<string, list<string>> $ptr - the PTR names, by address.
     */
    public function __construct(
        array $txt,
        array $mailHosts,
        array $failing = [],
        array $addresses = [],
        array $mx = [],
        array $ptr = []
    )
    {
        $this->m_txt = $txt;
        $this->m_mailHosts = $mailHosts;
        $this->m_failing = $failing;
        $this->m_addresses = $addresses;
        $this->m_mx = $mx;
        $this->m_ptr = $ptr;
    }


    public function fetchTxtRecords(string $name) : array
    {
        $this->failIfFailing($name);
        return $this->m_txt[$name] ?? [];
    }


    public function fetchHasMailHost(string $domain) : bool
    {
        return in_array($domain, $this->m_mailHosts, true);
    }


    public function fetchIpAddresses(string $name) : array
    {
        $this->failIfFailing($name);
        return $this->m_addresses[$name] ?? [];
    }


    public function fetchMxHosts(string $domain) : array
    {
        $this->failIfFailing($domain);
        return $this->m_mx[$domain] ?? [];
    }


    public function fetchPtrNames(string $ip) : array
    {
        $this->failIfFailing($ip);
        return $this->m_ptr[$ip] ?? [];
    }


    private function failIfFailing(string $name) : void
    {
        if (in_array($name, $this->m_failing, true))
        {
            throw new DnsLookupException("Looking up {$name} in DNS failed: timed out");
        }
    }
}


/*
 * A provider's API, answering each path (without its query string) with a made-up response, and recording every
 * request made.
 */
final class FakeHttpClient implements ClientInterface
{
    private array $m_responses;
    private array $m_requests;


    /**
     * @param array<string, array{0: int, 1: mixed}> $responses - the HTTP status and the JSON body, by path.
     */
    public function __construct(array $responses)
    {
        $this->m_responses = $responses;
        $this->m_requests = [];
    }


    public function sendRequest(RequestInterface $request) : ResponseInterface
    {
        $this->m_requests[] = $request;
        [$status, $body] = $this->m_responses[$request->getUri()->getPath()] ?? [404, ['errors' => []]];
        return new \GuzzleHttp\Psr7\Response($status, [], json_encode($body));
    }


    # Accessors
    public function getRequests() : array { return $this->m_requests; }
}
