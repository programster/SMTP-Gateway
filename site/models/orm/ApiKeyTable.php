<?php

/*
 * The "api_key" table. Use this for loading and deleting ApiKey objects.
 */

declare(strict_types = 1);

use Programster\PgsqlLib\PgSqlConnection;
use Programster\PgsqlObjects\AbstractTable;
use Programster\PgsqlObjects\Exceptions\ExceptionInvalidId;


class ApiKeyTable extends AbstractTable
{
    public function getObjectClassName() : string
    {
        return ApiKey::class;
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
        return ['created_at'];
    }


    public function getVirtualFields() : array
    {
        return [];
    }


    public function getTableName() : string
    {
        return "api_key";
    }


    /**
     * Generate the ID for a new API key, the start of the token (see Token::generate()). This is only used if a
     * row is inserted without one, as ApiKeyRepository::create() gives each the ID of the token it generated.
     *
     * @return string
     */
    public function generateId() : string
    {
        return Token::generate(TokenKind::API_KEY)->getId();
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
     * Check an ID is one of this table's, e.g. "ak_8af651aadbe3ff25", in lower case.
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

        if (TokenKind::API_KEY->isId($normalized) === false)
        {
            throw new ExceptionInvalidId("\"{$id}\" isn't the ID of an API key, like \"ak_8af651aadbe3ff25\".");
        }

        return $normalized;
    }
}
