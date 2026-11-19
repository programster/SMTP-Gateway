<?php

/*
 * Tells whether a provider's SMTP reply refusing an email is because of the provider's own rate limit (or a daily or
 * monthly quota), rather than anything about the email, so the email can go through another provider (or wait), and
 * the rate limits set here for the provider can be checked. Providers word these differently, and don't share a code
 * for them, e.g.:
 *
 * - "421 4.7.0 Rate limit exceeded, try again later"
 * - "450 4.7.1 Too many messages, slow down"
 * - "451 4.7.1 Throttled: sending too fast"
 * - "550 5.7.1 Daily sending quota exceeded"
 *
 * so it's told by the wording, which is a good guess rather than certain. "Too many recipients" isn't one: that's about
 * the email itself.
 */

declare(strict_types = 1);


final class RateLimitReply
{
    private const array PATTERNS = [
        '/rate[ -]?limit/i',
        '/throttl/i',
        '/too many (messages|emails|mails|requests|connections|sends)/i',
        '/(sending|send|message|daily|hourly|monthly) (limit|quota)/i',
        '/quota (exceeded|reached|exhausted)/i',
        '/(limit|quota) (has been |was )?(exceeded|reached)/i',
        '/exceeded (your|the|its) (\w+ )?(limit|quota)/i',
        '/sending too (fast|quickly)/i',
    ];


    /**
     * Whether an SMTP reply refusing an email says the provider's rate limit (or quota) was reached.
     *
     * @param string $code - the reply's code, e.g. "421".
     *
     * @param string $text - the rest of the reply, e.g. "4.7.0 Rate limit exceeded".
     *
     * @return bool
     */
    public static function isRateLimit(string $code, string $text) : bool
    {
        $isRefusal = (preg_match('/^[45]\d\d$/', trim($code)) === 1);
        $isRateLimit = false;

        foreach (self::PATTERNS as $pattern)
        {
            $isRateLimit = ($isRateLimit || preg_match($pattern, $text) === 1);
        }

        return $isRefusal && $isRateLimit && preg_match('/too many recipients/i', $text) !== 1;
    }
}
