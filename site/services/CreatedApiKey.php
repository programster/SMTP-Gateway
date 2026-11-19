<?php

/*
 * A newly created API key, for the management API: its record, and the key itself. This is the only time the key is
 * ever available, as only the hash of its secret is stored.
 */

declare(strict_types = 1);


readonly class CreatedApiKey
{
    private ApiKey $m_apiKey;
    private Token $m_token;


    public function __construct(ApiKey $apiKey, Token $token)
    {
        $this->m_apiKey = $apiKey;
        $this->m_token = $token;
    }


    # Accessors
    public function getApiKey() : ApiKey { return $this->m_apiKey; }
    public function getToken() : Token { return $this->m_token; }
}
