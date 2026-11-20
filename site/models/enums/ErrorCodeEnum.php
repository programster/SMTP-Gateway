<?php

/*
 * The application-specific error codes returned in the "code" of a JSON error response. These let a client tell
 * apart failures that share an HTTP status code. Never renumber an existing case, as clients may rely on it.
 */

declare(strict_types = 1);


enum ErrorCodeEnum : int
{
    case UNKNOWN = -1;

    // 400s about the request itself.
    case INVALID_JSON = 1;
    case INVALID_INPUT = 2;
    case NOT_FOUND = 3;
    case MESSAGE_TOO_LARGE = 4;
    case CONFLICT = 5;
    case MISSING_VERSION = 6;

    // 401/403s about the credentials.
    case MISSING_CREDENTIALS = 10;
    case INVALID_CREDENTIALS = 11;
    case REVOKED_CREDENTIALS = 12;
    case IP_NOT_ALLOWED = 13;
    case MFA_SETUP_REQUIRED = 14;
    case PASSWORD_CHANGE_REQUIRED = 15;

    // 403s about what an email token is allowed to send.
    case SENDER_NOT_ALLOWED = 20;
    case REPLY_TO_NOT_ALLOWED = 21;
    case RECIPIENT_NOT_ALLOWED = 22;
    case TOO_MANY_RECIPIENTS = 23;

    // No provider the email token may use takes the email's sender, so it could never be sent.
    case NO_PROVIDER_FOR_SENDER = 24;

    // 502 when the provider refused the email outright.
    case PROVIDER_REJECTED = 30;

    // 404 when there's nothing to export, as no providers have been set up yet.
    case PROVIDERS_NOT_CONFIGURED = 31;

    // 409s when an account is in the wrong state for what was asked (see AccountStateProblem).
    case ACCOUNT_NOT_ARCHIVED = 40;
    case ACCOUNT_ARCHIVED = 41;
    case LAST_ACTIVE_ACCOUNT = 42;


    /**
     * Get the error code for a rejected request to send an email.
     *
     * @param RejectionReason $reason - why it was rejected.
     *
     * @return ErrorCodeEnum
     */
    public static function createFromRejectionReason(RejectionReason $reason) : ErrorCodeEnum
    {
        return match ($reason) {
            RejectionReason::INVALID_CREDENTIALS => ErrorCodeEnum::INVALID_CREDENTIALS,
            RejectionReason::REVOKED_EMAIL_TOKEN => ErrorCodeEnum::REVOKED_CREDENTIALS,
            RejectionReason::IP_NOT_ALLOWED => ErrorCodeEnum::IP_NOT_ALLOWED,
            RejectionReason::SENDER_NOT_ALLOWED => ErrorCodeEnum::SENDER_NOT_ALLOWED,
            RejectionReason::REPLY_TO_NOT_ALLOWED => ErrorCodeEnum::REPLY_TO_NOT_ALLOWED,
            RejectionReason::RECIPIENT_NOT_ALLOWED => ErrorCodeEnum::RECIPIENT_NOT_ALLOWED,
            RejectionReason::MESSAGE_TOO_LARGE => ErrorCodeEnum::MESSAGE_TOO_LARGE,
            RejectionReason::INVALID_MESSAGE => ErrorCodeEnum::INVALID_INPUT,
            RejectionReason::PROVIDER_REJECTED => ErrorCodeEnum::PROVIDER_REJECTED,
            RejectionReason::TOO_MANY_RECIPIENTS => ErrorCodeEnum::TOO_MANY_RECIPIENTS,
            RejectionReason::NO_PROVIDER_FOR_SENDER => ErrorCodeEnum::NO_PROVIDER_FOR_SENDER,
        };
    }
}
