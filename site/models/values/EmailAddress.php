<?php

/*
 * A single, valid email address. The domain is stored in lowercase, as domains aren't case sensitive.
 */

declare(strict_types = 1);


readonly class EmailAddress
{
    private string $m_localPart;
    private string $m_domain;


    /**
     * @param string $address - the address, e.g. "someone@example.org". Surrounding whitespace is ignored.
     *
     * @throws InvalidInputException - if it isn't a valid email address.
     */
    public function __construct(string $address)
    {
        $address = trim($address);
        $atPosition = strrpos($address, '@');

        if (
               strlen($address) > 320
            || $atPosition === false
            || filter_var($address, FILTER_VALIDATE_EMAIL, FILTER_FLAG_EMAIL_UNICODE) === false
        )
        {
            throw new InvalidInputException("\"{$address}\" isn't a valid email address.");
        }

        $this->m_localPart = substr($address, 0, $atPosition);
        $this->m_domain = strtolower(substr($address, $atPosition + 1));
    }


    /**
     * Check whether this is the same address as another. The comparison ignores case, as in practice no mail
     * server treats "Bob@example.org" and "bob@example.org" as different people.
     *
     * @param EmailAddress $other - the address to compare against.
     *
     * @return bool
     */
    public function isSameAs(EmailAddress $other) : bool
    {
        return (
               mb_strtolower($this->m_localPart) === mb_strtolower($other->getLocalPart())
            && $this->m_domain === $other->getDomain()
        );
    }


    public function __toString() : string
    {
        return $this->m_localPart . '@' . $this->m_domain;
    }


    # Accessors
    public function getLocalPart() : string { return $this->m_localPart; }
    public function getDomain() : string { return $this->m_domain; }
}
