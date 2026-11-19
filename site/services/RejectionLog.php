<?php

/*
 * Records requests to send email that were turned away, so misuse (or a misconfigured service) shows up in the
 * statistics.
 */

declare(strict_types = 1);


final class RejectionLog
{
    /**
     * Record a rejected request. This never throws, as failing to record a rejection mustn't change the response
     * the client gets.
     *
     * @param string $emailTokenId - the ID of the token that was used, if a real one was given, or an empty string.
     *
     * @param MessageSource $source - how the request came in.
     *
     * @param IpAddress $clientIp - where it came from.
     *
     * @param RequestRejectedException $rejection - why it was rejected.
     *
     * @return void
     */
    public static function record(
        string $emailTokenId,
        MessageSource $source,
        IpAddress $clientIp,
        RequestRejectedException $rejection
    ) : void
    {
        try
        {
            RejectedRequestTable::getInstance()->insert([
                'email_token_id' => ($emailTokenId === '') ? null : $emailTokenId,
                'source' => $source->value,
                'client_ip' => (string)$clientIp,
                'reason' => $rejection->getReason()->value,
                'detail' => mb_substr($rejection->getMessage(), 0, 1000),
                'created_at' => Time::now(),
            ]);
        }
        catch (Throwable $e)
        {
            $logger = ServiceFactory::getLogger(LogCategory::EMAIL);
            $logger->error('Failed to record a rejected request', ['error' => $e->getMessage()]);
        }
    }
}
