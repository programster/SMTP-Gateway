<?php

/*
 * What a set of rate limits counts across: everything (the global limits), one email token's emails, or what was handed
 * to one provider. Create one with global(), forEmailToken() or forProvider().
 *
 * The global and per-token limits count each email once, when it was last handed over (email_message), so a token isn't
 * charged again when a provider fails and the email is retried. The per-provider limits count every attempt the
 * provider was given (email_attempt), failed ones included, as each one was a request to it.
 */

declare(strict_types = 1);


readonly class RateLimitScope
{
    // The table counted: email_message or email_attempt. Both have "id", "dispatched_at" and "recipient_count".
    private string $m_table;

    // The SQL condition on that table that picks out the rows in this scope (with its values escaped).
    private string $m_sqlCondition;


    private function __construct(string $table, string $sqlCondition)
    {
        $this->m_table = $table;
        $this->m_sqlCondition = $sqlCondition;
    }


    public static function global() : RateLimitScope
    {
        return new RateLimitScope('"email_message"', 'TRUE');
    }


    public static function forEmailToken(string $emailTokenId) : RateLimitScope
    {
        return new RateLimitScope('"email_message"', '"email_token_id" = ' . DB::escape($emailTokenId));
    }


    public static function forProvider(string $providerName) : RateLimitScope
    {
        return new RateLimitScope('"email_attempt"', '"provider" = ' . DB::escape($providerName));
    }


    # Accessors
    public function getTable() : string { return $this->m_table; }
    public function getSqlCondition() : string { return $this->m_sqlCondition; }
}
