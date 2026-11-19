<?php

/*
 * The part of an email that an address rule restricts.
 */

declare(strict_types = 1);


enum AddressRuleField : string
{
    // The address the email says it's from: the envelope sender (MAIL FROM), and the From and Sender headers.
    case SENDER = 'sender';

    // The addresses in the Reply-To header.
    case REPLY_TO = 'reply_to';

    // Everyone the email is delivered to (To, Cc and Bcc alike).
    case RECIPIENT = 'recipient';


    public function getLabel() : string
    {
        return match ($this) {
            AddressRuleField::SENDER => 'Allowed senders',
            AddressRuleField::REPLY_TO => 'Allowed reply-to addresses',
            AddressRuleField::RECIPIENT => 'Allowed recipients',
        };
    }
}
