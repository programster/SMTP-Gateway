<?php

/*
 * Creates, loads, changes and revokes email tokens, along with their policies (stored across the email_token_network,
 * email_token_address_rule, email_token_provider and email_token_rate_limit tables, and email_token's own
 * restricts_providers and priority columns).
 */

declare(strict_types = 1);

use Programster\PgsqlLib\Query;
use Programster\PgsqlObjects\CacheMode;
use Programster\PgsqlObjects\OrmTransaction;
use Programster\PgsqlObjects\SortBy;
use Programster\PgsqlObjects\SortOrder;


final class EmailTokenRepository
{
    /**
     * Create a new email token, with a newly generated ID and secret.
     *
     * @param string $name - a name to recognise the token by, e.g. the service that will use it.
     *
     * @param EmailTokenPolicy $policy - what the token is restricted to.
     *
     * @return CreatedEmailToken - the token's record, and the token itself, which is never available again.
     *
     * @throws InvalidInputException - if the name is empty or too long.
     */
    public static function create(string $name, EmailTokenPolicy $policy) : CreatedEmailToken
    {
        $name = self::validateName($name);
        $token = Token::generate(TokenKind::EMAIL_TOKEN);

        $emailToken = EmailToken::createFromArray([
            'id' => $token->getId(),
            'name' => $name,
            'secret_hash' => $token->getSecretHash(),
            'created_at' => Time::now(),
            'last_used_at' => null,
            'last_used_ip' => null,
            'revoked_at' => null,
            'priority' => $policy->getPriority()->getValue(),
            'restricts_providers' => self::isProviderRestricted($policy),
        ]);

        // The token and its policy's rows are saved together, or not at all.
        $transaction = new OrmTransaction(ServiceFactory::getDb());
        $transaction->save($emailToken);
        self::addPolicyToTransaction($transaction, (string)$emailToken->getId(), $policy);
        $transaction->commit();

        return new CreatedEmailToken($emailToken, $token);
    }


    /**
     * Change a token's name and policy. Its ID and secret stay the same, so whatever uses it carries on working,
     * within its new restrictions from its next request to the JSON API, or its next SMTP login (a connection that's
     * already logged in keeps the policy it logged in with). Changed rate limits and providers apply to its emails
     * already in the queue too, as they're checked when each is sent, but a changed priority only applies to emails
     * queued from now on. Only the name, priority and restricts_providers of the token's own row are changed, so it
     * can't undo a revocation, or the record of its last use, made at the same moment.
     *
     * @param EmailToken $emailToken - the token.
     *
     * @param string $name - its name, which may be the one it has now.
     *
     * @param EmailTokenPolicy $policy - what it's restricted to now, replacing its current policy entirely.
     *
     * @return void
     *
     * @throws InvalidInputException - if the name is empty or too long.
     *
     * @throws ConflictException - if the token has been revoked, as a revoked token can't be changed.
     */
    public static function update(EmailToken $emailToken, string $name, EmailTokenPolicy $policy) : void
    {
        $name = self::validateName($name);
        $id = (string)$emailToken->getId();

        $updateToken = new Query(
            'UPDATE "email_token" SET "name" = $1, "priority" = $2, "restricts_providers" = $3'
            . ' WHERE "id" = $4 AND "revoked_at" IS NULL',
            [$name, $policy->getPriority()->getValue(), self::isProviderRestricted($policy), $id]
        );

        // The token's row, and its policy's rows, are changed together, or not at all.
        $transaction = new OrmTransaction(ServiceFactory::getDb(), [$updateToken]);

        $tables = [
            'email_token_network',
            'email_token_address_rule',
            'email_token_provider',
            'email_token_rate_limit',
        ];

        foreach ($tables as $table)
        {
            $transaction->append(new Query("DELETE FROM \"{$table}\" WHERE \"email_token_id\" = \$1", [$id]));
        }

        self::addPolicyToTransaction($transaction, $id, $policy);
        $results = $transaction->commit();

        // If it was revoked just now, its policy's rows were replaced, which has no effect, as it can't be used.
        if (pg_affected_rows($results[0]) !== 1)
        {
            throw new ConflictException('This email token has been revoked, so it can\'t be changed.');
        }
    }


    /**
     * Load a token, bypassing the ORM's cache, so a revocation is always seen.
     *
     * @param string $id - the token's ID.
     *
     * @return EmailToken
     *
     * @throws NotFoundException - if there's no such token.
     */
    public static function load(string $id) : EmailToken
    {
        try
        {
            if (TokenKind::EMAIL_TOKEN->isId($id) === false)
            {
                throw new NotFoundException("There is no email token with the ID \"{$id}\".");
            }

            /* @var $emailToken EmailToken */
            $emailToken = EmailTokenTable::getInstance()->load($id, CacheMode::IGNORE_CACHE);
        }
        catch (\Programster\PgsqlObjects\Exceptions\ExceptionNoSuchIdException)
        {
            throw new NotFoundException("There is no email token with the ID \"{$id}\".");
        }

        return $emailToken;
    }


    /**
     * Load every token, newest first.
     *
     * @param bool $includeRevoked - whether to include revoked tokens.
     *
     * @return EmailTokenCollection
     */
    public static function loadAll(bool $includeRevoked = true) : EmailTokenCollection
    {
        $table = EmailTokenTable::getInstance();
        $newestFirst = new SortBy('created_at', SortOrder::DESC);

        $tokens = $includeRevoked
            ? $table->loadAll(CacheMode::IGNORE_CACHE, $newestFirst)
            : $table->loadWhereAnd(['revoked_at' => null], CacheMode::IGNORE_CACHE, $newestFirst);

        return new EmailTokenCollection(...$tokens);
    }


    /**
     * Load the policy of a token.
     *
     * @param EmailToken $emailToken - the token.
     *
     * @return EmailTokenPolicy
     *
     * @throws Exception - if the stored policy is invalid, which the database's constraints should prevent.
     */
    public static function loadPolicy(EmailToken $emailToken) : EmailTokenPolicy
    {
        $where = ['email_token_id' => $emailToken->getId()];
        $networks = [];
        $rulesByField = [];
        $rateLimits = [];

        foreach (EmailTokenNetworkTable::getInstance()->loadWhereAnd($where, CacheMode::IGNORE_CACHE) as $record)
        {
            /* @var $record EmailTokenNetwork */
            $networks[] = new IpNetwork($record->getNetwork());
        }

        $records = EmailTokenAddressRuleRecordTable::getInstance()->loadWhereAnd($where, CacheMode::IGNORE_CACHE);

        foreach ($records as $record)
        {
            /* @var $record EmailTokenAddressRuleRecord */
            $kind = AddressRuleKind::from($record->getKind());
            $rulesByField[$record->getField()][] = AddressRule::createFromStoredForm($kind, $record->getValue());
        }

        foreach (EmailTokenRateLimitTable::getInstance()->loadWhereAnd($where, CacheMode::IGNORE_CACHE) as $record)
        {
            /* @var $record EmailTokenRateLimit */
            $unit = RateLimitUnit::from($record->getUnit());
            $rateLimits[] = new RateLimit($unit, $record->getMaximum(), $record->getPeriodSeconds());
        }

        // A field with no rules (or no networks) has no condition on it, so allows anyone.
        $createRestriction = function (AddressRuleField $field) use ($rulesByField) : AddressRestrictionInterface {
            $rules = $rulesByField[$field->value] ?? [];
            return (count($rules) === 0) ? new AnyAddress() : new AddressRuleSet(...$rules);
        };

        return new EmailTokenPolicy(
            allowedNetworks: (count($networks) === 0) ? new AnyIp() : new IpAllowList(...$networks),
            allowedSenders: $createRestriction(AddressRuleField::SENDER),
            allowedReplyTos: $createRestriction(AddressRuleField::REPLY_TO),
            allowedRecipients: $createRestriction(AddressRuleField::RECIPIENT),
            allowedProviders: self::loadProviderRestriction((string)$emailToken->getId()),
            rateLimits: new RateLimitSet(...$rateLimits),
            priority: Priority::forEmailToken($emailToken->getPriority()),
        );
    }


    /**
     * Load just a token's rate limits, which is all the queue worker needs.
     *
     * @param string $emailTokenId - the token's ID.
     *
     * @return RateLimitSet
     */
    public static function loadRateLimits(string $emailTokenId) : RateLimitSet
    {
        $wherePairs = ['email_token_id' => $emailTokenId];
        $limits = [];

        foreach (EmailTokenRateLimitTable::getInstance()->loadWhereAnd($wherePairs, CacheMode::IGNORE_CACHE) as $limit)
        {
            /* @var $limit EmailTokenRateLimit */
            $unit = RateLimitUnit::from($limit->getUnit());
            $limits[] = new RateLimit($unit, $limit->getMaximum(), $limit->getPeriodSeconds());
        }

        return new RateLimitSet(...$limits);
    }


    /**
     * Load just which providers a token's emails may go through, e.g. for the queue worker, as each email is sent.
     *
     * @param string $emailTokenId - the token's ID.
     *
     * @return ProviderRestrictionInterface - AnyProvider if it isn't restricted, NoRemainingProviders if every
     * provider it was restricted to has since been removed, or otherwise a ProviderAllowList.
     */
    public static function loadProviderRestriction(string $emailTokenId) : ProviderRestrictionInterface
    {
        $id = ServiceFactory::getDb()->escapeValue($emailTokenId);
        $restrictsQuery = "SELECT \"restricts_providers\" FROM \"email_token\" WHERE \"id\" = {$id}";
        $restricts = pg_fetch_all(ServiceFactory::getDb()->query($restrictsQuery));
        $isRestricted = (count($restricts) === 1 && $restricts[0]['restricts_providers'] === 't');
        $wherePairs = ['email_token_id' => $emailTokenId];
        $links = EmailTokenProviderTable::getInstance()->loadWhereAnd($wherePairs, CacheMode::IGNORE_CACHE);
        $providerIds = [];

        foreach ($links as $link)
        {
            /* @var $link EmailTokenProvider */
            $providerIds[] = new ProviderId($link->getProviderId());
        }

        return match (true) {
            !$isRestricted => new AnyProvider(),
            count($providerIds) === 0 => new NoRemainingProviders(),
            default => new ProviderAllowList(...$providerIds),
        };
    }


    /**
     * Load every email token (not revoked) that's restricted to particular providers, with the providers each is linked
     * to, e.g. for a provider's page to say which tokens use it, and warn before it's removed or disabled.
     * @return ProviderTokenLinkCollection
     */
    public static function loadProviderLinks() : ProviderTokenLinkCollection
    {
        $query =
            'SELECT "email_token"."id", "email_token_provider"."provider_id" FROM "email_token"'
            . ' JOIN "email_token_provider" ON "email_token_provider"."email_token_id" = "email_token"."id"'
            . ' WHERE "email_token"."revoked_at" IS NULL ORDER BY "email_token"."name", "email_token"."id"';

        $providerIdsByToken = [];

        foreach (pg_fetch_all(ServiceFactory::getDb()->query($query)) as $row)
        {
            $providerIdsByToken[$row['id']][] = new ProviderId($row['provider_id']);
        }

        $links = [];

        foreach ($providerIdsByToken as $emailTokenId => $providerIds)
        {
            $ids = new ProviderIdCollection(...$providerIds);
            $links[] = new ProviderTokenLink(self::load((string)$emailTokenId), $ids);
        }

        return new ProviderTokenLinkCollection(...$links);
    }


    /**
     * Remove the links from email tokens to providers that no longer exist, after the providers have been saved, so a
     * removed provider's links are dropped. The tokens stay restricted to their other providers (or to none, if it was
     * their last).
     *
     * @param ProviderIdCollection $remaining - the ids of the providers that still exist.
     *
     * @return EmailTokenCollection - the tokens (not revoked) that lost a link, to say so.
     */
    public static function removeLinksToOtherProviders(ProviderIdCollection $remaining) : EmailTokenCollection
    {
        $ids = ServiceFactory::getDb()->escapeValues($remaining->toStrings());
        $notIn = (count($ids) === 0) ? '' : ' WHERE "provider_id" NOT IN (' . implode(', ', $ids) . ')';
        $query = "DELETE FROM \"email_token_provider\"{$notIn} RETURNING \"email_token_id\"";
        $rows = pg_fetch_all(ServiceFactory::getDb()->query($query));
        $emailTokens = [];

        foreach (array_unique(array_column($rows, 'email_token_id')) as $emailTokenId)
        {
            $emailToken = self::load($emailTokenId);

            if (!$emailToken->isRevoked())
            {
                $emailTokens[] = $emailToken;
            }
        }

        return new EmailTokenCollection(...$emailTokens);
    }


    /**
     * Revoke a token, so it can no longer be used, and cancel any of its emails that are still waiting in the queue
     * (if a token is being revoked because it leaked, those emails shouldn't go out either). The token's record stays,
     * so its history still shows in the statistics. If the cancelled emails' content is kept but their attachments
     * aren't, the attachments are removed within five minutes, by scripts/remove-attachments.php.
     *
     * @param EmailToken $emailToken - the token.
     *
     * @return int - how many queued emails were cancelled.
     */
    public static function revoke(EmailToken $emailToken) : int
    {
        $id = ServiceFactory::getDb()->escapeValue($emailToken->getId());

        // While the retention settings are invalid, nothing is deleted, so the content is kept.
        try
        {
            $clearContent = Settings::get()->getRetention()->getEmailRetention()->getClearContentOnFinishSql();
        }
        catch (ConfigurationException)
        {
            $clearContent = '';
        }

        $revokeToken =
            "UPDATE \"email_token\" SET \"revoked_at\" = unix_time() WHERE \"id\" = {$id} AND \"revoked_at\" IS NULL";

        $cancelQueued =
            "UPDATE \"email_message\" SET \"status\" = 'cancelled'{$clearContent}"
            . " WHERE \"email_token_id\" = {$id} AND \"status\" = 'queued'";

        $transaction = new OrmTransaction(ServiceFactory::getDb(), [$revokeToken, $cancelQueued]);
        $results = $transaction->commit();
        return pg_affected_rows($results[1]);
    }


    /**
     * Count a token's emails waiting in the queue: the ones revoking it would cancel (see revoke()).
     * @param EmailToken $emailToken - the token.
     * @return int
     */
    public static function countQueuedEmails(EmailToken $emailToken) : int
    {
        $query = 'SELECT count(*) AS "queued" FROM "email_message" WHERE "email_token_id" = $1 AND "status" = $2';
        $result = ServiceFactory::getDb()->query($query, [$emailToken->getId(), MessageStatus::QUEUED->value]);
        return (int)pg_fetch_result($result, 0, 'queued');
    }


    /**
     * Record that a token has just been used, and from where.
     * @param EmailToken $emailToken - the token.
     * @param IpAddress $clientIp - the IP address it was used from.
     * @return void
     */
    public static function markUsed(EmailToken $emailToken, IpAddress $clientIp) : void
    {
        $query = 'UPDATE "email_token" SET "last_used_at" = unix_time(), "last_used_ip" = $1 WHERE "id" = $2';
        ServiceFactory::getDb()->query($query, [(string)$clientIp, $emailToken->getId()]);
    }


    /**
     * Load the email tokens that have been used, most recently used first.
     * @param int $limit - the most to load.
     * @return EmailTokenCollection
     */
    public static function loadRecentlyUsed(int $limit) : EmailTokenCollection
    {
        $emailTokens = [];

        $query =
            'SELECT "id" FROM "email_token" WHERE "last_used_at" IS NOT NULL'
            . ' ORDER BY "last_used_at" DESC, "id" DESC LIMIT ';

        foreach (pg_fetch_all(ServiceFactory::getDb()->query($query . $limit)) as $row)
        {
            $emailTokens[] = self::load($row['id']);
        }

        return new EmailTokenCollection(...$emailTokens);
    }


    /**
     * @throws InvalidInputException - if the name is empty or too long.
     */
    public static function validateName(string $name) : string
    {
        $name = trim($name);

        if ($name === '' || mb_strlen($name) > 255)
        {
            throw new InvalidInputException('The name must be from 1 to 255 characters long.');
        }

        return $name;
    }


    /**
     * Add the rows of a token's policy to a transaction, to be saved with the token.
     *
     * @param OrmTransaction $transaction - the transaction the token is being saved in.
     *
     * @param string $emailTokenId - the token's ID.
     *
     * @param EmailTokenPolicy $policy - the policy.
     *
     * @return void
     */
    private static function addPolicyToTransaction(
        OrmTransaction $transaction,
        string $emailTokenId,
        EmailTokenPolicy $policy
    ) : void
    {
        // No restriction (AnyIp, AnyAddress, AnyProvider) is stored as no rows. For the providers, so is
        // NoRemainingProviders, which email_token's restricts_providers tells apart.
        $networks = $policy->getAllowedNetworks();
        $providers = $policy->getAllowedProviders();

        if ($networks instanceof IpAllowList)
        {
            foreach ($networks->getNetworks() as $network)
            {
                $row = ['email_token_id' => $emailTokenId, 'network' => (string)$network];
                $transaction->save(EmailTokenNetwork::createFromArray($row));
            }
        }

        foreach (AddressRuleField::cases() as $field)
        {
            $restriction = $policy->getRulesFor($field);
            $rules = ($restriction instanceof AddressRuleSet) ? $restriction->getRules() : new AddressRuleCollection();

            foreach ($rules as $rule)
            {
                /* @var $rule AddressRule */
                $record = EmailTokenAddressRuleRecord::createFromArray([
                    'email_token_id' => $emailTokenId,
                    'field' => $field->value,
                    'kind' => $rule->getKind()->value,
                    'value' => $rule->getValue(),
                ]);

                $transaction->save($record);
            }
        }

        if ($providers instanceof ProviderAllowList)
        {
            foreach ($providers->getIds() as $providerId)
            {
                /* @var $providerId ProviderId */
                $row = ['email_token_id' => $emailTokenId, 'provider_id' => $providerId->getValue()];
                $transaction->save(EmailTokenProvider::createFromArray($row));
            }
        }

        foreach ($policy->getRateLimits()->getLimits() as $limit)
        {
            /* @var $limit RateLimit */
            $record = EmailTokenRateLimit::createFromArray([
                'email_token_id' => $emailTokenId,
                'unit' => $limit->getUnit()->value,
                'maximum' => $limit->getMax(),
                'period_seconds' => $limit->getPeriodSeconds(),
            ]);

            $transaction->save($record);
        }
    }


    /**
     * Check whether a policy restricts which providers the token's emails go through, as stored in email_token's
     * restricts_providers.
     */
    private static function isProviderRestricted(EmailTokenPolicy $policy) : bool
    {
        return !($policy->getAllowedProviders() instanceof AnyProvider);
    }
}
