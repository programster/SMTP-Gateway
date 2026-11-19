<?php

/*
 * Parses the address list in an email header (e.g. From, Reply-To) into its addresses, following RFC 5322:
 * display names, quoted strings, comments and groups are all handled.
 *
 * This is used for security checks, so it fails closed: anything it can't parse throws, rather than being skipped.
 * (PHPMailer's fallback parser silently drops what it can't parse, which would let "From: ceo@bank.example
 * (comment)" past a sender restriction.)
 */

declare(strict_types = 1);


final class AddressListParser
{
    /**
     * Parse an address list.
     *
     * @param string $header - the header's value (already unfolded), e.g.
     * "\"Doe, Jane\" <jane@example.org>, bob@x.org".
     *
     * @return EmailAddressCollection - the addresses, in order. Never empty.
     *
     * @throws InvalidInputException - if it can't be parsed, an address is invalid, or there are no addresses.
     */
    public static function parse(string $header) : EmailAddressCollection
    {
        $addresses = [];

        foreach (self::splitIntoMailboxes(self::removeComments($header)) as $mailbox)
        {
            $addresses[] = new EmailAddress(self::extractAddress($mailbox));
        }

        if (count($addresses) === 0)
        {
            throw new InvalidInputException("No email addresses were found in \"{$header}\".");
        }

        return new EmailAddressCollection(...$addresses);
    }


    /**
     * Remove the (possibly nested) comments from a header, leaving quoted strings alone.
     *
     * @param string $header - the header's value.
     *
     * @return string
     *
     * @throws InvalidInputException - if a comment or quoted string isn't closed.
     */
    private static function removeComments(string $header) : string
    {
        $output = '';
        $depth = 0;
        $inQuotes = false;
        $length = strlen($header);
        $i = 0;

        while ($i < $length)
        {
            $char = $header[$i];

            if ($char === '\\' && ($inQuotes || $depth > 0))
            {
                // An escaped character (quoted-pair). Keep it if it's inside quotes.
                if ($depth === 0)
                {
                    $output .= substr($header, $i, 2);
                }

                $i += 2;
            }
            else
            {
                if ($depth === 0 && $char === '"')
                {
                    $inQuotes = !$inQuotes;
                    $output .= $char;
                }
                elseif ($inQuotes === false && $char === '(')
                {
                    $depth++;
                }
                elseif ($inQuotes === false && $char === ')')
                {
                    if ($depth === 0)
                    {
                        throw new InvalidInputException("\"{$header}\" has an unmatched \")\".");
                    }

                    $depth--;
                    $output .= ($depth === 0) ? ' ' : '';
                }
                elseif ($depth === 0)
                {
                    $output .= $char;
                }

                $i++;
            }
        }

        if ($depth !== 0 || $inQuotes)
        {
            throw new InvalidInputException("\"{$header}\" has an unclosed comment or quoted string.");
        }

        return $output;
    }


    /**
     * Split a header (with its comments removed) into its mailboxes, at the commas that aren't in quotes or angle
     * brackets. Group syntax ("Team: a@x.org, b@x.org;") is flattened into its members.
     *
     * @param string $header - the header's value, with no comments.
     *
     * @return string[] - each mailbox, e.g. "\"Jane\" <jane@example.org>". Empty ones are left out.
     *
     * @throws InvalidInputException - if an angle bracket isn't closed.
     */
    private static function splitIntoMailboxes(string $header) : array
    {
        $mailboxes = [];
        $current = '';
        $inQuotes = false;
        $inAngles = false;
        $length = strlen($header);

        for ($i = 0; $i < $length; $i++)
        {
            $char = $header[$i];

            if ($inQuotes && $char === '\\')
            {
                $current .= substr($header, $i, 2);
                $i++;
            }
            elseif ($char === '"' && $inAngles === false)
            {
                $inQuotes = !$inQuotes;
                $current .= $char;
            }
            elseif ($inQuotes === false && $char === '<')
            {
                $inAngles = true;
                $current .= $char;
            }
            elseif ($inQuotes === false && $char === '>')
            {
                $inAngles = false;
                $current .= $char;
            }
            elseif ($inQuotes === false && $inAngles === false && $char === ':')
            {
                // The start of a group. What came before was the group's name, not a mailbox.
                $current = '';
            }
            elseif ($inQuotes === false && $inAngles === false && ($char === ',' || $char === ';'))
            {
                $mailboxes[] = $current;
                $current = '';
            }
            else
            {
                $current .= $char;
            }
        }

        if ($inAngles)
        {
            throw new InvalidInputException("\"{$header}\" has an unclosed \"<\".");
        }

        $mailboxes[] = $current;
        $trimmed = [];

        foreach ($mailboxes as $mailbox)
        {
            if (trim($mailbox) !== '')
            {
                $trimmed[] = trim($mailbox);
            }
        }

        return $trimmed;
    }


    /**
     * Get the address from a single mailbox, which is either a bare address, or a display name followed by the
     * address in angle brackets.
     *
     * @param string $mailbox - the mailbox, e.g. "Jane <jane@example.org>".
     *
     * @return string - the address, e.g. "jane@example.org".
     *
     * @throws InvalidInputException - if it has more than one angle-bracketed address.
     */
    private static function extractAddress(string $mailbox) : string
    {
        $matchCount = preg_match_all('/<([^<>]*)>/', $mailbox, $matches);

        if ($matchCount > 1)
        {
            throw new InvalidInputException("\"{$mailbox}\" contains more than one address in angle brackets.");
        }

        return ($matchCount === 1) ? trim($matches[1][0]) : $mailbox;
    }
}
