<?php

/*
 * Creates, finds and revokes the API keys for the management API, which are used as bearer tokens.
 */

declare(strict_types = 1);

use Programster\PgsqlObjects\CacheMode;
use Programster\PgsqlObjects\Transaction;


final class ApiKeyRepository
{
    /**
     * Create a new API key, with a newly generated ID and secret.
     *
     * @param string $name - a name to recognise the key by, e.g. what will use it.
     *
     * @param IpAllowList $allowedNetworks - the IP addresses/ranges the key may be used from.
     *
     * @return CreatedApiKey - the key's record, and the key itself, which is never available again.
     *
     * @throws InvalidInputException - if the name is empty or too long.
     */
    public static function create(string $name, IpAllowList $allowedNetworks) : CreatedApiKey
    {
        $name = EmailTokenRepository::validateName($name);
        $token = Token::generate(TokenKind::API_KEY);

        $apiKey = ApiKey::createFromArray([
            'id' => $token->getId(),
            'name' => $name,
            'secret_hash' => $token->getSecretHash(),
            'created_at' => Time::now(),
            'last_used_at' => null,
            'last_used_ip' => null,
            'revoked_at' => null,
        ]);

        // The key and its networks are saved together, in one query, or not at all.
        $transaction = new Transaction();
        $transaction->save($apiKey);

        foreach ($allowedNetworks->getNetworks() as $network)
        {
            $row = ['api_key_id' => (string)$apiKey->getId(), 'network' => (string)$network];
            $transaction->save(ApiKeyNetwork::createFromArray($row));
        }

        $transaction->commit();
        return new CreatedApiKey($apiKey, $token);
    }


    /**
     * Find the API key a client gave: the one with the token's ID, as long as the token's secret is that key's.
     *
     * @param Token $token - the key, as given.
     *
     * @return ApiKey
     *
     * @throws NotFoundException - if there's no key with its ID, or its secret is wrong. Which one isn't said, so
     * the response doesn't reveal which IDs exist.
     */
    public static function findByToken(Token $token) : ApiKey
    {
        try
        {
            $apiKey = self::load($token->getId());
        }
        catch (NotFoundException)
        {
            throw new NotFoundException('There is no such API key.');
        }

        if ($token->matchesHash($apiKey->getSecretHash()) === false)
        {
            throw new NotFoundException('There is no such API key.');
        }

        return $apiKey;
    }


    /**
     * @throws NotFoundException - if there's no such key.
     */
    public static function load(string $id) : ApiKey
    {
        try
        {
            if (TokenKind::API_KEY->isId($id) === false)
            {
                throw new NotFoundException("There is no API key with the ID \"{$id}\".");
            }

            /* @var $apiKey ApiKey */
            $apiKey = ApiKeyTable::getInstance()->load($id, CacheMode::IGNORE_CACHE);
        }
        catch (\Programster\PgsqlObjects\Exceptions\ExceptionNoSuchIdException)
        {
            throw new NotFoundException("There is no API key with the ID \"{$id}\".");
        }

        return $apiKey;
    }


    /**
     * Load every API key, newest first.
     *
     * @return ApiKeyCollection
     */
    public static function loadAll() : ApiKeyCollection
    {
        $apiKeys = [];

        foreach (DB::fetchRows('SELECT "id" FROM "api_key" ORDER BY "created_at" DESC') as $row)
        {
            $apiKeys[] = self::load($row['id']);
        }

        return new ApiKeyCollection(...$apiKeys);
    }


    public static function loadAllowedNetworks(ApiKey $apiKey) : IpAllowList
    {
        $networks = [];

        $where = ['api_key_id' => $apiKey->getId()];

        foreach (ApiKeyNetworkTable::getInstance()->loadWhereAnd($where, CacheMode::IGNORE_CACHE) as $record)
        {
            /* @var $record ApiKeyNetwork */
            $networks[] = new IpNetwork($record->getNetwork());
        }

        return new IpAllowList(...$networks);
    }


    public static function revoke(ApiKey $apiKey) : void
    {
        $id = DB::escape($apiKey->getId());
        $query = "UPDATE \"api_key\" SET \"revoked_at\" = now() WHERE \"id\" = {$id} AND \"revoked_at\" IS NULL";
        ServiceFactory::getDb()->query($query);
    }


    /**
     * Record that an API key has just been used, and from where.
     * @param ApiKey $apiKey - the API key.
     * @param IpAddress $clientIp - the IP address it was used from.
     * @return void
     */
    public static function markUsed(ApiKey $apiKey, IpAddress $clientIp) : void
    {
        $query = 'UPDATE "api_key" SET "last_used_at" = now(), "last_used_ip" = $1 WHERE "id" = $2';
        ServiceFactory::getDb()->query($query, [(string)$clientIp, $apiKey->getId()]);
    }


    /**
     * Load the API keys that have been used, most recently used first.
     * @param int $limit - the most to load.
     * @return ApiKeyCollection
     */
    public static function loadRecentlyUsed(int $limit) : ApiKeyCollection
    {
        $apiKeys = [];
        $query = 'SELECT "id" FROM "api_key" WHERE "last_used_at" IS NOT NULL ORDER BY "last_used_at" DESC LIMIT ';

        foreach (DB::fetchRows($query . $limit) as $row)
        {
            $apiKeys[] = self::load($row['id']);
        }

        return new ApiKeyCollection(...$apiKeys);
    }
}
