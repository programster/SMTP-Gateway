<?php

/*
 * Turns the restrictions sent to the API (JSON) or the web interface (forms) into the typed objects, so that every
 * mistake is reported with a message saying what was wrong.
 */

declare(strict_types = 1);


final class PolicyInput
{
    // The rate limit rows on the web forms.
    // The most rows of a list (or of rate limits) a form can send. The form adds rows one at a time, up to this.
    public const int MAX_FORM_ROWS = 50;

    // The fields of an email token that can be set through the API, and those it shows but that can't be changed.
    private const array WRITABLE_FIELDS = ['name', 'conditions', 'rateLimits', 'priority'];
    private const array READ_ONLY_FIELDS = ['id', 'createdAt', 'lastUsedAt', 'revokedAt'];


    /**
     * Read a new token's policy from a POST request body. Its restrictions are an optional "conditions" list, each
     * restricting one field, e.g. [{"field": "recipient", "allowed": [{"type": "domain", "value": "example.org"}]}].
     * A field with no condition has no restriction. A field that isn't one of a token's (e.g. a misspelt
     * "conditons") is refused, rather than ignored, which would create a token with no restrictions.
     *
     * @param array $body - the decoded JSON body, which may also have "name" (read by the caller).
     *
     * @return EmailTokenPolicy
     *
     * @throws InvalidInputException - if a field isn't one of a token's, or anything is invalid.
     */
    public static function readPolicyFromJson(array $body) : EmailTokenPolicy
    {
        self::assertOnlyWritableFields($body);
        return self::readPolicyFieldsFromJson($body);
    }


    /**
     * Read a token's new policy from a PUT request, which replaces the whole token, so must give every field: "name",
     * "conditions", "rateLimits" and "priority" (with an empty list for no conditions, or no rate limits). Leaving one
     * out is refused, rather than taken to mean its default, so a PUT that only meant to change the name can't remove
     * every restriction by mistake.
     *
     * @param array $body - the decoded JSON body.
     *
     * @return EmailTokenPolicy
     *
     * @throws InvalidInputException - if a field is missing, isn't one of a token's, or is invalid.
     */
    public static function readReplacementPolicyFromJson(array $body) : EmailTokenPolicy
    {
        self::assertOnlyWritableFields($body);
        $missing = array_values(array_diff(self::WRITABLE_FIELDS, array_keys($body)));

        if (count($missing) > 0)
        {
            $fields = '"' . implode('", "', self::WRITABLE_FIELDS) . '"';

            $msg =
                "PUT replaces the whole email token, so it needs every one of {$fields} (an empty list for no"
                . ' conditions, or no rate limits). Missing: "' . implode('", "', $missing) . '". To change only some'
                . ' of them, use PATCH.';

            throw new InvalidInputException($msg);
        }

        return self::readPolicyFieldsFromJson($body);
    }


    /**
     * Read a token's new policy from a PATCH request, which only changes what it gives. "rateLimits" and "priority"
     * replace the token's, if given. Each condition given replaces the token's condition on that field, and the rest
     * are kept, so changing the senders can't drop the IP address restriction by mistake. A condition with
     * "allowed": null removes it, so that field has no restriction.
     *
     * @param array $body - the decoded JSON body, which may also have "name" (read by the caller).
     *
     * @param EmailTokenPolicy $current - the token's policy now.
     *
     * @return EmailTokenPolicy
     *
     * @throws InvalidInputException - if a field isn't one of a token's, or is invalid.
     */
    public static function readPolicyPatchFromJson(array $body, EmailTokenPolicy $current) : EmailTokenPolicy
    {
        self::assertOnlyWritableFields($body);
        $conditions = self::readConditionsFromJson($body, ConditionRemoval::ALLOWED);

        $ip = $conditions[ConditionField::CLIENT_IP->value] ?? null;

        if ($ip === null)
        {
            $networks = $current->getAllowedNetworks();
        }
        elseif ($ip['allowed'] === null)
        {
            $networks = new AnyIp();
        }
        else
        {
            $networks = self::readNetworksFromJson($ip['allowed'], $ip['path']);
        }

        $senders = self::patchRules($conditions, ConditionField::SENDER, $current->getAllowedSenders());
        $replyTos = self::patchRules($conditions, ConditionField::REPLY_TO, $current->getAllowedReplyTos());
        $recipients = self::patchRules($conditions, ConditionField::RECIPIENT, $current->getAllowedRecipients());
        $providerCondition = $conditions[ConditionField::PROVIDER->value] ?? null;

        if ($providerCondition === null)
        {
            $providers = $current->getAllowedProviders();
        }
        elseif ($providerCondition['allowed'] === null)
        {
            $providers = new AnyProvider();
        }
        else
        {
            $providers = self::readProvidersFromJson($providerCondition['allowed'], $providerCondition['path']);
        }

        $hasRateLimits = array_key_exists('rateLimits', $body);
        $rateLimits = $hasRateLimits ? self::readRateLimitsFromJson($body, 'rateLimits') : $current->getRateLimits();
        $priority = array_key_exists('priority', $body) ? self::readPriorityFromJson($body) : $current->getPriority();

        return new EmailTokenPolicy(
            allowedNetworks: $networks,
            allowedSenders: $senders,
            allowedReplyTos: $replyTos,
            allowedRecipients: $recipients,
            allowedProviders: $providers,
            rateLimits: $rateLimits,
            priority: $priority,
        );
    }


    /**
     * Read a list of rate limits from a JSON field, e.g. "rateLimits": [{"unit": "emails", "max": 10,
     * "periodSeconds": 1}, {"unit": "recipients", "max": 50000, "periodSeconds": 2592000}]. The field is optional, and
     * means no limit when left out.
     *
     * @throws InvalidInputException - if it's invalid.
     */
    public static function readRateLimitsFromJson(array $body, string $field) : RateLimitSet
    {
        $limits = [];
        $units = implode(' or ', array_column(RateLimitUnit::cases(), 'value'));

        foreach ((array_key_exists($field, $body) ? self::readList($body, $field) : []) as $index => $item)
        {
            $unit = is_array($item) ? RateLimitUnit::tryFrom((string)($item['unit'] ?? '')) : null;

            if ($unit === null || !is_int($item['max'] ?? null) || !is_int($item['periodSeconds'] ?? null))
            {
                $msg =
                    "{$field}[{$index}] must be an object with a \"unit\" ({$units}), and integer \"max\" and"
                    . " \"periodSeconds\".";

                throw new InvalidInputException($msg);
            }

            $createLimit = function () use ($unit, $item) : RateLimit {
                return new RateLimit($unit, $item['max'], $item['periodSeconds']);
            };

            $limits[] = self::prefixError("{$field}[{$index}]", $createLimit);
        }

        return new RateLimitSet(...$limits);
    }


    /**
     * Read a token's policy from the web interface's form. A list left with no rows has no restriction. The providers
     * are restricted only when "restrict_providers" is ticked, to those whose "allowed_provider_<id>" box is ticked
     * (see ViewEmailTokenFields), at least one.
     *
     * @param Closure $getField - gets a form field's value by name, as a string.
     *
     * @return EmailTokenPolicy
     *
     * @throws InvalidInputException - if anything is invalid.
     */
    public static function readPolicyFromForm(Closure $getField) : EmailTokenPolicy
    {
        $readRules = function (string $prefix, string $label) use ($getField) : AddressRestrictionInterface {
            $rules = [];

            foreach (self::readValueRowsFromForm($getField, $prefix) as $row => $value)
            {
                $createRule = function () use ($value) : AddressRule {
                    return AddressRule::createFromString($value);
                };

                $rules[] = self::prefixError("{$label}, row {$row}", $createRule);
            }

            return (count($rules) === 0) ? new AnyAddress() : new AddressRuleSet(...$rules);
        };

        $hasIps = count(self::readValueRowsFromForm($getField, 'allowed_ips')) > 0;

        return new EmailTokenPolicy(
            allowedNetworks: $hasIps ? self::readIpAllowListFromForm($getField) : new AnyIp(),
            allowedSenders: $readRules('allowed_senders', AddressRuleField::SENDER->getLabel()),
            allowedReplyTos: $readRules('allowed_reply_tos', AddressRuleField::REPLY_TO->getLabel()),
            allowedRecipients: $readRules('allowed_recipients', AddressRuleField::RECIPIENT->getLabel()),
            allowedProviders: self::prefixError('Providers', self::createProviderFormReader($getField)),
            rateLimits: self::readRateLimitsFromForm($getField),
            priority: self::prefixError('Priority', self::createPriorityReader((string)$getField('priority'))),
        );
    }


    /**
     * Read the "allowed_ips" rows of a form (see ViewValueRows): the IP addresses and CIDR ranges an API key (or an
     * email token restricted by IP address) may be used from, at least one.
     *
     * @param Closure $getField - gets a form field's value by name, as a string.
     *
     * @return IpAllowList
     *
     * @throws InvalidInputException - if a row isn't an address or range, or there are none, saying which row.
     */
    public static function readIpAllowListFromForm(Closure $getField) : IpAllowList
    {
        $networks = [];

        foreach (self::readValueRowsFromForm($getField, 'allowed_ips') as $row => $value)
        {
            $createNetwork = function () use ($value) : IpNetwork {
                return new IpNetwork($value);
            };

            $networks[] = self::prefixError("Allowed IP addresses, row {$row}", $createNetwork);
        }

        $readNetworks = function () use ($networks) : IpAllowList {
            return new IpAllowList(...$networks);
        };

        return self::prefixError('Allowed IP addresses', $readNetworks);
    }


    /**
     * Read the rows of a list on a form (see ViewValueRows): "<prefix>_0", "<prefix>_1" and so on. Rows left empty
     * are ignored.
     *
     * @param Closure $getField - gets a form field's value by name, as a string.
     *
     * @param string $prefix - the rows' name, e.g. "allowed_ips".
     *
     * @return array<int, string> - each row's value, trimmed, keyed by its number as it's shown (from 1), so an
     * error can say which row it's in.
     */
    public static function readValueRowsFromForm(Closure $getField, string $prefix) : array
    {
        $values = [];

        for ($i = 0; $i < self::MAX_FORM_ROWS; $i++)
        {
            $value = trim($getField("{$prefix}_{$i}"));

            if ($value !== '')
            {
                $values[count($values) + 1] = $value;
            }
        }

        return $values;
    }


    /**
     * Read the rate limit rows of a form: "rate_limit_max_<n>" (a number), "rate_limit_unit_<n>" (emails or
     * recipients) and "rate_limit_period_<n>" (seconds, from a select). Rows with no number are ignored, so leaving
     * every row empty means no limit.
     *
     * @param Closure $getField - gets a form field's value by name, as a string.
     *
     * @return RateLimitSet
     *
     * @throws InvalidInputException - if a row is invalid.
     */
    public static function readRateLimitsFromForm(Closure $getField) : RateLimitSet
    {
        $limits = [];

        for ($i = 0; $i < self::MAX_FORM_ROWS; $i++)
        {
            $max = trim($getField("rate_limit_max_{$i}"));
            $unit = RateLimitUnit::tryFrom(trim($getField("rate_limit_unit_{$i}")));
            $period = trim($getField("rate_limit_period_{$i}"));

            if ($max !== '')
            {
                if (ctype_digit($max) === false || ctype_digit($period) === false || $unit === null)
                {
                    $msg = "Rate limit " . ($i + 1) . " must be a whole number of emails or recipients.";
                    throw new InvalidInputException($msg);
                }

                $limits[] = new RateLimit($unit, (int)$max, (int)$period);
            }
        }

        return new RateLimitSet(...$limits);
    }


    /**
     * Read the optional "priority" field: a whole number from 1 to Priority::MAX_EMAIL_TOKEN_PRIORITY, 1 if it's left
     * out.
     *
     * @throws InvalidInputException - if it's there, but isn't a whole number in that range.
     */
    private static function readPriorityFromJson(array $body) : Priority
    {
        $value = $body['priority'] ?? Priority::DEFAULT;

        if (is_int($value) === false)
        {
            $msg = '"priority" must be a whole number from 1 to ' . Priority::MAX_EMAIL_TOKEN_PRIORITY . '.';
            throw new InvalidInputException($msg);
        }

        $create = function () use ($value) : Priority {
            return Priority::forEmailToken($value);
        };

        return self::prefixError('priority', $create);
    }


    /**
     * Create a reader for the providers on a token's form, for prefixError(): any provider if "restrict_providers"
     * isn't ticked, or otherwise the ones ticked, of those in settings.yaml (so only real ones can be).
     *
     * @param Closure $getField - gets a form field's value by name, as a string.
     *
     * @return Closure - returns the ProviderRestrictionInterface, or throws an InvalidInputException.
     */
    private static function createProviderFormReader(Closure $getField) : Closure
    {
        $read = function () use ($getField) : ProviderRestrictionInterface {
            $ids = [];

            if ($getField('restrict_providers') === '1')
            {
                foreach (self::loadConfiguredProviders() as $provider)
                {
                    /* @var $provider ProviderDefinition */
                    if ($getField("allowed_provider_{$provider->getId()}") === '1')
                    {
                        $ids[] = $provider->getId();
                    }
                }

                if (count($ids) === 0)
                {
                    $msg = 'Tick at least one provider, or untick "Only send through particular providers".';
                    throw new InvalidInputException($msg);
                }
            }

            return (count($ids) === 0) ? new AnyProvider() : new ProviderAllowList(...$ids);
        };

        return $read;
    }


    /**
     * Read the "allowed" list of a provider condition: the ids of providers in settings.yaml (enabled or not), e.g.
     * ["0b7f1c2e-5a3d-4e8f-9b6a-2c4d6e8f0a1b"]. Each is checked to be a real provider, so a mistyped id is refused
     * here, rather than leaving the token unable to send.
     *
     * @param array $items - the list, which readConditionsFromJson() has checked is a list, and isn't empty.
     *
     * @param string $path - where it is in the request, for errors, e.g. "conditions[0].allowed".
     *
     * @return ProviderAllowList
     *
     * @throws InvalidInputException - if an item isn't the id of a provider.
     */
    private static function readProvidersFromJson(array $items, string $path) : ProviderAllowList
    {
        $config = self::loadConfiguredProviders();
        $ids = [];

        foreach ($items as $index => $value)
        {
            if (!is_string($value))
            {
                throw new InvalidInputException("{$path}[{$index}] must be a provider's id, as a string.");
            }

            $createId = function () use ($value) : ProviderId {
                return new ProviderId($value);
            };

            $id = self::prefixError("{$path}[{$index}]", $createId);
            $isKnown = false;

            foreach ($config as $provider)
            {
                /* @var $provider ProviderDefinition */
                $isKnown = ($isKnown || $provider->getId()->equals($id));
            }

            if (!$isKnown)
            {
                $msg = "{$path}[{$index}]: there's no provider with the id {$id}. GET /api/v1/providers lists them.";
                throw new InvalidInputException($msg);
            }

            $ids[] = $id;
        }

        return new ProviderAllowList(...$ids);
    }


    /**
     * Get the providers in settings.yaml (enabled or not), which a token can be restricted to.
     *
     * @return ProviderDefinitionCollection
     *
     * @throws InvalidInputException - if there are none yet, or they can't be read, as then no provider can be chosen.
     */
    private static function loadConfiguredProviders() : ProviderDefinitionCollection
    {
        try
        {
            $providers = ProviderRegistry::getConfig()->getProviders();
        }
        catch (ProvidersNotConfiguredException)
        {
            $msg = "There are no providers yet, so it can't be restricted to any. Add one on the Providers page first.";
            throw new InvalidInputException($msg);
        }
        catch (ConfigurationException $e)
        {
            throw new InvalidInputException("The providers can't be read, so none can be chosen: {$e->getMessage()}");
        }

        return $providers;
    }


    private static function createPriorityReader(string $text) : Closure
    {
        $read = function () use ($text) : Priority {
            return Priority::forKeyFromString($text);
        };

        return $read;
    }


    /**
     * Read the "allowed" list of a client IP condition, e.g. ["10.0.0.0/8", "192.168.1.20"].
     *
     * @param array $items - the list, which readConditionsFromJson() has checked is a list, and isn't empty.
     *
     * @param string $path - where it is in the request, for errors, e.g. "conditions[0].allowed".
     *
     * @return IpAllowList
     *
     * @throws InvalidInputException - if an item isn't an IP address or CIDR range.
     */
    private static function readNetworksFromJson(array $items, string $path) : IpAllowList
    {
        $networks = [];

        foreach ($items as $index => $value)
        {
            if (!is_string($value))
            {
                throw new InvalidInputException("{$path}[{$index}] must be a string, such as \"10.0.0.0/8\".");
            }

            $createNetwork = function () use ($value) : IpNetwork {
                return new IpNetwork($value);
            };

            $networks[] = self::prefixError("{$path}[{$index}]", $createNetwork);
        }

        return new IpAllowList(...$networks);
    }


    /**
     * Read the optional "conditions" list of a request body, checking each condition's shape: a known "field", given
     * at most once, and a non-empty "allowed" list. What's in each list is read by readNetworksFromJson() or
     * readRulesFromJson().
     *
     * @param array $body - the decoded JSON body.
     *
     * @param ConditionRemoval $removal - whether "allowed": null may be given, to remove a condition (in a PATCH).
     *
     * @return array<string, array{allowed: array|null, path: string}> - each condition's "allowed" list (or null, to
     * remove it), and where it is in the request (for errors), by its field's value.
     *
     * @throws InvalidInputException - if "conditions" or one of them is invalid, or a field is given twice.
     */
    private static function readConditionsFromJson(array $body, ConditionRemoval $removal) : array
    {
        $conditions = [];
        $fields = implode(', ', array_column(ConditionField::cases(), 'value'));

        foreach ((array_key_exists('conditions', $body) ? self::readList($body, 'conditions') : []) as $index => $item)
        {
            $path = "conditions[{$index}]";
            $field = is_array($item) ? ConditionField::tryFrom((string)($item['field'] ?? '')) : null;
            $allowed = is_array($item) ? ($item['allowed'] ?? null) : null;

            if ($field === null)
            {
                throw new InvalidInputException("{$path} must be an object with a \"field\" of {$fields}.");
            }

            $isRemoving = ($allowed === null && $removal === ConditionRemoval::ALLOWED);
            $orNull = ($removal === ConditionRemoval::ALLOWED) ? ', or null to remove the condition' : '';

            if ($isRemoving === false && (!is_array($allowed) || !array_is_list($allowed)))
            {
                throw new InvalidInputException("{$path}.allowed must be an array{$orNull}.");
            }

            if ($isRemoving === false && count($allowed) === 0)
            {
                $msg =
                    "{$path}.allowed is empty, which would allow nobody. To allow anyone, leave out the"
                    . " \"{$field->value}\" condition.";

                throw new InvalidInputException($msg);
            }

            if (isset($conditions[$field->value]))
            {
                $msg = "{$path} is a second \"{$field->value}\" condition. Put all of its values in one.";
                throw new InvalidInputException($msg);
            }

            $conditions[$field->value] = ['allowed' => $allowed, 'path' => "{$path}.allowed"];
        }

        return $conditions;
    }


    /**
     * Read the "allowed" list of an address condition, e.g.
     * [{"type": "domain", "value": "example.org"}, {"type": "address", "value": "a@b.org"}].
     *
     * @param array $items - the list, which readConditionsFromJson() has checked is a list, and isn't empty.
     *
     * @param string $path - where it is in the request, for errors, e.g. "conditions[1].allowed".
     *
     * @return AddressRuleSet
     *
     * @throws InvalidInputException - if a rule is invalid.
     */
    private static function readRulesFromJson(array $items, string $path) : AddressRuleSet
    {
        $rules = [];
        $kinds = implode(' or ', array_column(AddressRuleKind::cases(), 'value'));

        foreach ($items as $index => $item)
        {
            $kind = is_array($item) ? AddressRuleKind::tryFrom((string)($item['type'] ?? '')) : null;
            $value = is_array($item) ? ($item['value'] ?? null) : null;

            if ($kind === null || !is_string($value))
            {
                $msg =
                    "{$path}[{$index}] must be an object with a \"type\" of {$kinds}, and a string \"value\" (the"
                    . " domain or address). To allow anyone, leave the condition out.";

                throw new InvalidInputException($msg);
            }

            $createRule = function () use ($kind, $value) : AddressRule {
                return AddressRule::createFromStoredForm($kind, $value);
            };

            $rules[] = self::prefixError("{$path}[{$index}]", $createRule);
        }

        return new AddressRuleSet(...$rules);
    }


    /**
     * Read a token's policy from the fields of an API request body: its "conditions", "rateLimits" and "priority",
     * each optional, and no restriction (or the default priority) when left out.
     *
     * @param array $body - the decoded JSON body, whose fields have already been checked to be a token's.
     *
     * @return EmailTokenPolicy
     *
     * @throws InvalidInputException - if anything is invalid.
     */
    private static function readPolicyFieldsFromJson(array $body) : EmailTokenPolicy
    {
        $conditions = self::readConditionsFromJson($body, ConditionRemoval::REFUSED);

        $readRules = function (ConditionField $field) use ($conditions) : AddressRestrictionInterface {
            return isset($conditions[$field->value])
                ? self::readRulesFromJson($conditions[$field->value]['allowed'], $conditions[$field->value]['path'])
                : new AnyAddress();
        };

        $ip = $conditions[ConditionField::CLIENT_IP->value] ?? null;
        $provider = $conditions[ConditionField::PROVIDER->value] ?? null;

        $providers = ($provider === null)
            ? new AnyProvider()
            : self::readProvidersFromJson($provider['allowed'], $provider['path']);

        return new EmailTokenPolicy(
            allowedNetworks: ($ip === null) ? new AnyIp() : self::readNetworksFromJson($ip['allowed'], $ip['path']),
            allowedSenders: $readRules(ConditionField::SENDER),
            allowedReplyTos: $readRules(ConditionField::REPLY_TO),
            allowedRecipients: $readRules(ConditionField::RECIPIENT),
            allowedProviders: $providers,
            rateLimits: self::readRateLimitsFromJson($body, 'rateLimits'),
            priority: self::readPriorityFromJson($body),
        );
    }


    /**
     * Get one address field's restriction after a PATCH: the one it gives, none if it removes it, or the one there was.
     *
     * @param array<string, array{allowed: array|null, path: string}> $conditions - the PATCH's conditions, as
     * readConditionsFromJson() reads them.
     *
     * @param ConditionField $field - the field.
     *
     * @param AddressRestrictionInterface $now - the field's restriction now.
     *
     * @return AddressRestrictionInterface
     *
     * @throws InvalidInputException - if the rules given are invalid.
     */
    private static function patchRules(
        array $conditions,
        ConditionField $field,
        AddressRestrictionInterface $now
    ) : AddressRestrictionInterface
    {
        $condition = $conditions[$field->value] ?? null;

        if ($condition === null)
        {
            $restriction = $now;
        }
        elseif ($condition['allowed'] === null)
        {
            $restriction = new AnyAddress();
        }
        else
        {
            $restriction = self::readRulesFromJson($condition['allowed'], $condition['path']);
        }

        return $restriction;
    }


    /**
     * Check a POST, PUT or PATCH body only has fields that can be set, so a misspelt one (which would otherwise be
     * ignored, leaving that part of the token unrestricted, or unchanged) is refused, saying so.
     *
     * @param array $body - the decoded JSON body.
     *
     * @return void
     *
     * @throws InvalidInputException - if it has a field that can't be changed, or that a token doesn't have.
     */
    private static function assertOnlyWritableFields(array $body) : void
    {
        $writable = '"' . implode('", "', self::WRITABLE_FIELDS) . '"';

        foreach (array_keys($body) as $field)
        {
            if (in_array($field, self::READ_ONLY_FIELDS, true))
            {
                $msg = "\"{$field}\" is set by the service, so it can't be given. Leave it out.";
                throw new InvalidInputException($msg);
            }

            if (in_array($field, self::WRITABLE_FIELDS, true) === false)
            {
                $msg = "\"{$field}\" isn't a field of an email token. The ones that can be given are {$writable}.";
                throw new InvalidInputException($msg);
            }
        }
    }


    /**
     * @throws InvalidInputException - if the field isn't a JSON array.
     */
    private static function readList(array $body, string $field) : array
    {
        $value = $body[$field] ?? null;

        if (!is_array($value) || !array_is_list($value))
        {
            throw new InvalidInputException("\"{$field}\" must be an array.");
        }

        return $value;
    }


    /**
     * Run a callback, and put which field was being read at the start of any error it throws.
     *
     * @param string $field - the field's name or label.
     *
     * @param Closure $read - the callback.
     *
     * @return mixed - what the callback returned.
     *
     * @throws InvalidInputException
     */
    private static function prefixError(string $field, Closure $read) : mixed
    {
        try
        {
            $result = $read();
        }
        catch (InvalidInputException $e)
        {
            throw new InvalidInputException("{$field}: {$e->getMessage()}");
        }

        return $result;
    }
}
