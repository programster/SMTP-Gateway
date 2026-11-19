<?php

/*
 * Looks up the DNS records the sender checks need. SystemDnsResolver uses the system's resolver, and the tests use a
 * fake with made-up records.
 */

declare(strict_types = 1);


interface DnsResolverInterface
{
    /**
     * Fetch the TXT records at a name, each with its strings joined together (as SPF and DMARC records are read).
     *
     * @param string $name - the name, e.g. "_dmarc.example.org".
     *
     * @return list<string> - the records, or an empty list if there are none (or there's no such name).
     *
     * @throws DnsLookupException - if the lookup failed.
     */
    public function fetchTxtRecords(string $name) : array;


    /**
     * Fetch whether a domain can receive email: it has an MX record (other than a "null MX", which says it receives
     * none), or failing that, an address record (which mail servers fall back to).
     *
     * @param string $domain - the domain, e.g. "example.org".
     *
     * @return bool
     *
     * @throws DnsLookupException - if the lookup failed.
     */
    public function fetchHasMailHost(string $domain) : bool;


    /**
     * Fetch the addresses a name resolves to: its A and AAAA records.
     *
     * @param string $name - the name, e.g. "mail.example.org".
     *
     * @return list<string> - the IPv4 and IPv6 addresses, or an empty list if there are none.
     *
     * @throws DnsLookupException - if the lookup failed.
     */
    public function fetchIpAddresses(string $name) : array;


    /**
     * Fetch the mail servers a domain's MX records point at.
     *
     * @param string $domain - the domain.
     *
     * @return list<string> - their hostnames, best (lowest preference) first, or an empty list if there are none.
     *
     * @throws DnsLookupException - if the lookup failed.
     */
    public function fetchMxHosts(string $domain) : array;


    /**
     * Fetch the reverse DNS (PTR) names of an address.
     *
     * @param string $ip - the IPv4 or IPv6 address.
     *
     * @return list<string> - the names, without a trailing dot, or an empty list if it has none.
     *
     * @throws DnsLookupException - if the lookup failed.
     */
    public function fetchPtrNames(string $ip) : array;
}
