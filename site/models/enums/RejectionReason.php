<?php

/*
 * Why a request to send an email was turned away.
 */

declare(strict_types = 1);


enum RejectionReason : string
{
    case INVALID_CREDENTIALS = 'invalid_credentials';
    case REVOKED_EMAIL_TOKEN = 'revoked_email_token';
    case IP_NOT_ALLOWED = 'ip_not_allowed';
    case SENDER_NOT_ALLOWED = 'sender_not_allowed';
    case REPLY_TO_NOT_ALLOWED = 'reply_to_not_allowed';
    case RECIPIENT_NOT_ALLOWED = 'recipient_not_allowed';
    case MESSAGE_TOO_LARGE = 'message_too_large';
    case INVALID_MESSAGE = 'invalid_message';
    case PROVIDER_REJECTED = 'provider_rejected';

    // More recipients than a rate limit allows, so it could never be sent, however long it waited.
    case TOO_MANY_RECIPIENTS = 'too_many_recipients';

    // No provider the email token may use takes the email's sender (see ProviderDefinition::takesAll()), so it could
    // never be sent.
    case NO_PROVIDER_FOR_SENDER = 'no_provider_for_sender';


    /**
     * Get the rejection reason for an address that a token's rules don't allow in the given field.
     *
     * @param AddressRuleField $field - the field the address was in.
     *
     * @return RejectionReason
     */
    public static function createForField(AddressRuleField $field) : RejectionReason
    {
        return match ($field) {
            AddressRuleField::SENDER => RejectionReason::SENDER_NOT_ALLOWED,
            AddressRuleField::REPLY_TO => RejectionReason::REPLY_TO_NOT_ALLOWED,
            AddressRuleField::RECIPIENT => RejectionReason::RECIPIENT_NOT_ALLOWED,
        };
    }
}
