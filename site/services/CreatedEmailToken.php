<?php

/*
 * A newly created email token: its record, and the token itself. This is the only time the token is ever available,
 * as only the hash of its secret is stored.
 */

declare(strict_types = 1);


readonly class CreatedEmailToken
{
    private EmailToken $m_emailToken;
    private Token $m_token;


    public function __construct(EmailToken $emailToken, Token $token)
    {
        $this->m_emailToken = $emailToken;
        $this->m_token = $token;
    }


    # Accessors
    public function getEmailToken() : EmailToken { return $this->m_emailToken; }
    public function getToken() : Token { return $this->m_token; }
}
