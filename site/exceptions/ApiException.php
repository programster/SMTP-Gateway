<?php

/*
 * Thrown by a JSON endpoint to respond with an error, in the shape
 * { "error": { "message": "...", "code": <ErrorCodeEnum> } }, with the given HTTP status code.
 */

declare(strict_types = 1);

use Programster\Http\HttpCode;


class ApiException extends Exception
{
    private readonly HttpCode $m_httpStatus;
    private readonly ErrorCodeEnum $m_errorCode;


    /**
     * @param HttpCode $httpStatus - the HTTP status code.
     *
     * @param ErrorCodeEnum $errorCode - the application's code for the error.
     *
     * @param string $message - the message, which is shown to the client.
     */
    public function __construct(HttpCode $httpStatus, ErrorCodeEnum $errorCode, string $message)
    {
        parent::__construct($message);
        $this->m_httpStatus = $httpStatus;
        $this->m_errorCode = $errorCode;
    }


    /**
     * Create the right exception for a rejected request to send an email.
     *
     * @param RequestRejectedException $rejection - why it was rejected.
     *
     * @return ApiException
     */
    public static function createFromRejection(RequestRejectedException $rejection) : ApiException
    {
        $status = match ($rejection->getReason()) {
            // A revoked email token is a 401, as a revoked API key is, as the credentials no longer work.
            RejectionReason::INVALID_CREDENTIALS, RejectionReason::REVOKED_EMAIL_TOKEN => HttpCode::UNAUTHORIZED,
            RejectionReason::MESSAGE_TOO_LARGE => HttpCode::PAYLOAD_TOO_LARGE,
            RejectionReason::INVALID_MESSAGE => HttpCode::BAD_REQUEST,
            RejectionReason::TOO_MANY_RECIPIENTS => HttpCode::BAD_REQUEST,
            RejectionReason::PROVIDER_REJECTED => HttpCode::BAD_GATEWAY,
            default => HttpCode::FORBIDDEN,
        };

        $code = ErrorCodeEnum::createFromRejectionReason($rejection->getReason());
        return new ApiException($status, $code, $rejection->getMessage());
    }


    /**
     * Get the body of the error response.
     *
     * @return array
     */
    public function toArray() : array
    {
        return ['error' => ['message' => $this->getMessage(), 'code' => $this->m_errorCode->value]];
    }


    # Accessors
    public function getHttpStatus() : HttpCode { return $this->m_httpStatus; }
    public function getErrorCode() : ErrorCodeEnum { return $this->m_errorCode; }
}
