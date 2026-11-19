<?php

/*
 * What a provider does with email from a sender it hasn't verified (see ProviderProfile).
 */

declare(strict_types = 1);


enum UnverifiedSenderHandling
{
    // It refuses the email (e.g. SendGrid, Postmark, Amazon SES).
    case REFUSED;

    // It sends it, but DKIM-signs it as one of its own domains rather than the sender's, so it fails DMARC.
    case SENT_UNALIGNED;
}
