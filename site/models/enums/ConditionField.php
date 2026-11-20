<?php

/*
 * What a condition on an email token restricts, as named in the API's "conditions" list: where the token is used
 * from, one part of the email, or the providers its emails go through. Each can be given at most once, and leaving one
 * out means no restriction on it.
 */

declare(strict_types = 1);


enum ConditionField : string
{
    // The IP address the token is used from.
    case CLIENT_IP = 'clientIp';

    // The envelope sender (MAIL FROM), and the From and Sender headers.
    case SENDER = 'sender';

    // The Reply-To header.
    case REPLY_TO = 'replyTo';

    // Every recipient: To, Cc and Bcc alike.
    case RECIPIENT = 'recipient';

    // The providers the token's emails go through, by their ids.
    case PROVIDER = 'provider';


    /**
     * Create the condition field for one part of an email.
     * @param AddressRuleField $field - the part of the email.
     * @return ConditionField
     */
    public static function createForAddressField(AddressRuleField $field) : ConditionField
    {
        return match ($field) {
            AddressRuleField::SENDER => ConditionField::SENDER,
            AddressRuleField::REPLY_TO => ConditionField::REPLY_TO,
            AddressRuleField::RECIPIENT => ConditionField::RECIPIENT,
        };
    }
}
