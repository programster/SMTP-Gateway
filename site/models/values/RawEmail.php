<?php

/*
 * An email in its raw (RFC 5322 / MIME) form, exactly as it will be handed to the provider: its headers, a blank
 * line, then its body. Line endings are always CRLF, as SMTP requires.
 */

declare(strict_types = 1);


readonly class RawEmail
{
    private string $m_headerBlock;
    private string $m_body;


    /**
     * @param string $raw - the whole email. LF or CRLF line endings are both accepted.
     *
     * @throws InvalidInputException - if it has no headers.
     */
    public function __construct(string $raw)
    {
        $raw = preg_replace('/\r?\n/', "\r\n", $raw);
        $separatorPosition = strpos($raw, "\r\n\r\n");

        if ($separatorPosition === false)
        {
            // An email with headers but no body at all.
            $headerBlock = rtrim($raw, "\r\n");
            $body = '';
        }
        else
        {
            $headerBlock = substr($raw, 0, $separatorPosition);
            $body = substr($raw, $separatorPosition + 4);
        }

        if (preg_match('/^[!-9;-~]+:/', $headerBlock) !== 1)
        {
            throw new InvalidInputException('The email must start with its headers (e.g. "From: ...").');
        }

        $this->m_headerBlock = $headerBlock;
        $this->m_body = $body;
    }


    /**
     * Get the values of every header with a name, unfolded onto one line each. Names aren't case sensitive.
     *
     * @param string $name - the header's name, e.g. "Reply-To".
     *
     * @return string[] - the values, in the order they appear. Empty if there's no such header.
     */
    public function getHeaderValues(string $name) : array
    {
        $values = [];

        foreach ($this->getUnfoldedHeaderLines() as $line)
        {
            $colonPosition = strpos($line, ':');

            if ($colonPosition !== false && strcasecmp(substr($line, 0, $colonPosition), $name) === 0)
            {
                $values[] = trim(substr($line, $colonPosition + 1));
            }
        }

        return $values;
    }


    /**
     * Get every address in every header with a name, e.g. all the addresses in the From headers.
     *
     * @param string $name - the header's name, e.g. "From".
     *
     * @return EmailAddressCollection - empty if there's no such header.
     *
     * @throws InvalidInputException - if a header with that name can't be parsed, or has no addresses.
     */
    public function getAddressesInHeader(string $name) : EmailAddressCollection
    {
        $addresses = [];

        foreach ($this->getHeaderValues($name) as $value)
        {
            foreach (AddressListParser::parse($value) as $address)
            {
                $addresses[] = $address;
            }
        }

        return new EmailAddressCollection(...$addresses);
    }


    /**
     * Get every address the email is sent as: its envelope sender, and the addresses in its From and Sender headers,
     * each once (ignoring case), e.g. to check a provider takes them all.
     *
     * @param EmailAddress $envelopeSender - the envelope sender (MAIL FROM), which isn't in the message itself.
     *
     * @return EmailAddressCollection
     *
     * @throws InvalidInputException - if the From or Sender header can't be parsed.
     */
    public function getSenderAddresses(EmailAddress $envelopeSender) : EmailAddressCollection
    {
        $unique = [];
        $all = [$envelopeSender, ...$this->getAddressesInHeader('From'), ...$this->getAddressesInHeader('Sender')];

        foreach ($all as $address)
        {
            /* @var $address EmailAddress */
            $key = mb_strtolower((string)$address);

            // The first of each is kept, so the envelope sender is as it was given.
            if (!isset($unique[$key]))
            {
                $unique[$key] = $address;
            }
        }

        return new EmailAddressCollection(...array_values($unique));
    }


    /**
     * Get the subject, decoded from its MIME encoded-word form (e.g. "=?UTF-8?B?...?=") into UTF-8.
     *
     * @return string - the subject, or an empty string if there isn't one.
     */
    public function getSubject() : string
    {
        $values = $this->getHeaderValues('Subject');
        $subject = '';

        if (count($values) > 0)
        {
            $decoded = iconv_mime_decode($values[0], ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');
            $subject = ($decoded === false) ? $values[0] : $decoded;
        }

        return mb_scrub($subject, 'UTF-8');
    }


    /**
     * Get a copy of this email without any header with a name.
     *
     * @param string $name - the header's name. Names aren't case sensitive.
     *
     * @return RawEmail
     */
    public function withoutHeader(string $name) : RawEmail
    {
        return $this->withoutHeadersMatching('/^' . preg_quote($name, '/') . '$/i');
    }


    /**
     * Get a copy of this email without any header whose name starts with a prefix, e.g. "X-Mailgun-", for removing
     * every one of a provider's control headers, including any it adds in future.
     *
     * @param string $prefix - the start of the names. Names aren't case sensitive.
     *
     * @return RawEmail
     *
     * @throws InvalidInputException - if the prefix is empty, which would remove every header.
     */
    public function withoutHeadersStartingWith(string $prefix) : RawEmail
    {
        if ($prefix === '')
        {
            throw new InvalidInputException('The prefix of the headers to remove can\'t be empty.');
        }

        return $this->withoutHeadersMatching('/^' . preg_quote($prefix, '/') . '/i');
    }


    /**
     * Get a copy of this email with a header added at the top.
     *
     * @param string $name - the header's name, e.g. "X-SMTPAPI".
     *
     * @param string $value - its value. It must be a single line.
     *
     * @return RawEmail
     *
     * @throws InvalidInputException - if the value contains a line break.
     */
    public function withHeaderPrepended(string $name, string $value) : RawEmail
    {
        if (preg_match('/[\r\n]/', $name . $value) === 1)
        {
            throw new InvalidInputException("The {$name} header can't contain a line break.");
        }

        return new RawEmail("{$name}: {$value}\r\n" . $this->m_headerBlock . "\r\n\r\n" . $this->m_body);
    }


    public function getSizeInBytes() : int
    {
        return strlen($this->toString());
    }


    public function toString() : string
    {
        return $this->m_headerBlock . "\r\n\r\n" . $this->m_body;
    }


    /**
     * Get the header lines, with each folded header joined back onto a single line.
     *
     * @return string[]
     */
    private function getUnfoldedHeaderLines() : array
    {
        $unfolded = preg_replace('/\r\n(?=[ \t])/', '', $this->m_headerBlock);
        return explode("\r\n", $unfolded);
    }


    /**
     * Get a copy of this email without the headers whose names match a pattern, along with their continuation
     * lines.
     *
     * @param string $pattern - the regular expression the names are matched against.
     *
     * @return RawEmail
     */
    private function withoutHeadersMatching(string $pattern) : RawEmail
    {
        $kept = [];
        $isRemoving = false;

        foreach (explode("\r\n", $this->m_headerBlock) as $line)
        {
            $isContinuation = (strlen($line) > 0 && ($line[0] === ' ' || $line[0] === "\t"));

            if ($isContinuation === false)
            {
                $colonPosition = strpos($line, ':');

                $isRemoving = (
                       $colonPosition !== false
                    && preg_match($pattern, trim(substr($line, 0, $colonPosition))) === 1
                );
            }

            if ($isRemoving === false)
            {
                $kept[] = $line;
            }
        }

        return new RawEmail(implode("\r\n", $kept) . "\r\n\r\n" . $this->m_body);
    }
}
