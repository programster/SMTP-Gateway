<?php

/*
 * The "email_token" table. Use this for loading and deleting EmailToken objects.
 */

declare(strict_types = 1);

use Programster\PgsqlLib\PgSqlConnection;
use Programster\PgsqlObjects\AbstractTable;
use Programster\PgsqlObjects\Exceptions\ExceptionInvalidId;


class EmailTokenTable extends AbstractTable
{
    public function getObjectClassName() : string
    {
        return EmailToken::class;
    }


    public function getDb() : PgSqlConnection
    {
        return ServiceFactory::getDb();
    }


    public function getFieldsThatAllowNull() : array
    {
        return ['last_used_at', 'last_used_ip', 'revoked_at'];
    }


    public function getFieldsThatHaveDefaults() : array
    {
        return ['created_at', 'priority'];
    }


    public function getVirtualFields() : array
    {
        return [];
    }


    public function getTableName() : string
    {
        return "email_token";
    }


    /**
     * Generate the ID for a new email token, the start of the token (see Token::generate()). This is only used if a
     * row is inserted without one, as EmailTokenRepository::create() gives each the ID of the token it generated.
     *
     * @return string
     */
    public function generateId() : string
    {
        return Token::generate(TokenKind::EMAIL_TOKEN)->getId();
    }


    public function canCodeGenerateId() : bool
    {
        return true;
    }


    public function canDatabaseGenerateId() : bool
    {
        return false;
    }


    /**
     * Check an ID is one of this table's, e.g. "et_8af651aadbe3ff25", in lower case.
     *
     * @param int|string $id - the ID.
     *
     * @return string
     *
     * @throws ExceptionInvalidId - if it isn't one.
     */
    public function normalizeId(int|string $id) : string
    {
        $normalized = strtolower(trim((string)$id));

        if (TokenKind::EMAIL_TOKEN->isId($normalized) === false)
        {
            throw new ExceptionInvalidId("\"{$id}\" isn't the ID of an email token, like \"et_8af651aadbe3ff25\".");
        }

        return $normalized;
    }
}
