<?php

/*
 * How many emails an email token sent in a period, for the dashboard's "most used email tokens".
 */

declare(strict_types = 1);


final readonly class EmailTokenUsage
{
    private EmailToken $m_emailToken;
    private int $m_emailCount;


    public function __construct(EmailToken $emailToken, int $emailCount)
    {
        $this->m_emailToken = $emailToken;
        $this->m_emailCount = $emailCount;
    }


    # Accessors
    public function getEmailToken() : EmailToken { return $this->m_emailToken; }
    public function getEmailCount() : int { return $this->m_emailCount; }
}
