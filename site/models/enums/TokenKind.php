<?php

/*
 * The two kinds of credential, each one string (see Token): API keys, for the management API, and email tokens,
 * which other services send email with. Each kind's prefix starts its ID, so either can be recognised, including
 * where it shouldn't be.
 */

declare(strict_types = 1);


enum TokenKind
{
    case API_KEY;
    case EMAIL_TOKEN;


    /**
     * Get the prefix its IDs start with, e.g. "ak_".
     * @return string
     */
    public function getPrefix() : string
    {
        return match ($this) {
            TokenKind::API_KEY => 'ak_',
            TokenKind::EMAIL_TOKEN => 'et_',
        };
    }


    /**
     * Get what it's called, e.g. "API key".
     * @return string
     */
    public function getLabel() : string
    {
        return match ($this) {
            TokenKind::API_KEY => 'API key',
            TokenKind::EMAIL_TOKEN => 'email token',
        };
    }


    /**
     * Get what it's for, e.g. "for the management API".
     * @return string
     */
    public function getPurpose() : string
    {
        return match ($this) {
            TokenKind::API_KEY => 'for the management API',
            TokenKind::EMAIL_TOKEN => 'for sending email',
        };
    }


    /**
     * Whether an ID is one of this kind's, e.g. "et_8af651aadbe3ff25".
     *
     * @param string $id - the ID.
     *
     * @return bool
     */
    public function isId(string $id) : bool
    {
        return preg_match('/^' . preg_quote($this->getPrefix(), '/') . '[0-9a-f]{16}$/', $id) === 1;
    }
}
