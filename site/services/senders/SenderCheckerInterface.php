<?php

/*
 * Checks, for one provider, whether it's set up to send as a key's senders (e.g. whether SendGrid has the domain
 * authenticated), and anything about the provider's account that stops it working as it should (e.g. its webhook).
 * Each provider type creates one with ProviderSettingsInterface::fetchSenderChecker(), which asks the provider's API
 * for everything it needs up front, so these methods don't make any requests to it. A checker that has no API to ask
 * (see CustomSenderChecker) may look up each sender's DNS records instead, as it checks them.
 *
 * Every check's source (see SenderCheck) is the provider's name.
 */

declare(strict_types = 1);


interface SenderCheckerInterface
{
    /**
     * Get the name of the provider in settings.yaml.
     *
     * @return string
     */
    public function getProviderName() : string;


    /**
     * Check the provider's account, rather than any one sender: its API key's permissions, its webhook, etc.
     *
     * @return SenderCheckCollection
     */
    public function checkAccount() : SenderCheckCollection;


    /**
     * Check whether the provider can send as any address at a domain (a "*@example.org" rule).
     *
     * @param DomainDns $dns - the domain's records.
     *
     * @return SenderCheckCollection
     */
    public function checkDomain(DomainDns $dns) : SenderCheckCollection;


    /**
     * Check whether the provider can send as one address.
     *
     * @param EmailAddress $address - the address.
     *
     * @param DomainDns $dns - the records of its domain.
     *
     * @return SenderCheckCollection
     */
    public function checkAddress(EmailAddress $address, DomainDns $dns) : SenderCheckCollection;


    /**
     * Check a key that may send as anyone (a "*" rule), whose senders can't be checked one by one.
     *
     * @return SenderCheckCollection
     */
    public function checkAnySender() : SenderCheckCollection;
}
