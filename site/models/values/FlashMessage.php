<?php

/*
 * A message carried over to the next page, e.g. "The API key was revoked."
 */

declare(strict_types = 1);


readonly class FlashMessage
{
    private string $m_message;
    private bool $m_isError;


    public function __construct(string $message, bool $isError)
    {
        $this->m_message = $message;
        $this->m_isError = $isError;
    }


    public function isEmpty() : bool
    {
        return $this->m_message === '';
    }


    # Accessors
    public function getMessage() : string { return $this->m_message; }
    public function isError() : bool { return $this->m_isError; }
}
