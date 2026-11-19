<?php

/*
 * One email address verified with a provider on its own, without its domain being authenticated (e.g. SendGrid's
 * "Single Sender Verification", or a Postmark sender signature), usually by clicking a link the provider emailed it.
 */

declare(strict_types = 1);


final readonly class VerifiedAddress
{
    private string $m_address;
    private bool $m_isVerified;


    /**
     * @param string $address - the address, e.g. "noreply@example.org".
     *
     * @param bool $isVerified - whether its verification has been finished (e.g. the emailed link clicked).
     */
    public function __construct(string $address, bool $isVerified)
    {
        $this->m_address = trim($address);
        $this->m_isVerified = $isVerified;
    }


    /**
     * Check whether this is the given address, ignoring case.
     *
     * @param EmailAddress $address - the address.
     *
     * @return bool
     */
    public function isAddress(EmailAddress $address) : bool
    {
        return (mb_strtolower($this->m_address) === mb_strtolower((string)$address));
    }


    /**
     * Check whether this address is at a domain.
     *
     * @param string $domain - the domain.
     *
     * @return bool
     */
    public function isAtDomain(string $domain) : bool
    {
        return str_ends_with(mb_strtolower($this->m_address), '@' . strtolower($domain));
    }


    # Accessors
    public function getAddress() : string { return $this->m_address; }
    public function isVerified() : bool { return $this->m_isVerified; }
}
