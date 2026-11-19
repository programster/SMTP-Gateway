<?php

/*
 * The kinds of address rule. Deliberately not regular expressions, which are too easy to get subtly wrong
 * (e.g. an unescaped "." or a missing "$" letting through far more than intended). There's no rule for "anyone": for
 * that, an email token has no condition on that part of the email (see AnyAddress).
 */

declare(strict_types = 1);


enum AddressRuleKind : string
{
    // Matches any address at exactly this domain (not its subdomains).
    case DOMAIN = 'domain';

    // Matches exactly this one address.
    case ADDRESS = 'address';
}
