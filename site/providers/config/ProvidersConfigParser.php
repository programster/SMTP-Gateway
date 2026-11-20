<?php

/*
 * Turns settings.yaml's providers section (or the same structure as JSON, from the API) into a ProvidersConfig, and
 * back again. Unknown keys are errors, so a typo (e.g. "ratelimits") is reported, rather than silently ignored.
 *
 * A provider's "id" (a UUID, which links email tokens to it) is optional. Without one, it keeps the id of the current
 * provider with the same name, or gets a new one if there isn't one, so a configuration written without ids (e.g. by
 * hand) doesn't unlink the providers it already had from their email tokens.
 *
 * The structure is:
 *
 *     mode: overflow                # round_robin, overflow or fallback
 *     providers:
 *       - id: 0b7f1c2e-5a3d-4e8f-9b6a-2c4d6e8f0a1b
 *         name: sendgrid-main
 *         type: sendgrid
 *         enabled: true
 *         api_key: SG.xxxx
 *         webhook_public_key: MFkwEwYHKoZI...
 *         rate_limits:
 *           - { unit: emails, max: 10, period_seconds: 1 }
 *         allowed_senders:          # optional: it takes any sender without it
 *           domains: [example.org]   # anyone at these domains,
 *           addresses: [someone@example.com]   # or any of these addresses
 *       - id: 6f1d9e3a-2b4c-4d5e-8f7a-1b2c3d4e5f60
 *         name: mailcow
 *         type: custom
 *         enabled: true
 *         host: mail.example.org
 *         port: 587
 *         security: tls
 *         username: relay@example.org
 *         password: xxxx
 *         dkim_selector: dkim
 *         rate_limits: []
 */

declare(strict_types = 1);


final class ProvidersConfigParser
{
    // A secret's value when it's being kept as it is, when the configuration is edited (see SecretOutput::MASKED).
    public const string UNCHANGED = '<unchanged>';

    private const array COMMON_KEYS = ['id', 'name', 'type', 'enabled', 'rate_limits', 'allowed_senders'];

    // The two lists in a provider's "allowed_senders": a sender is taken if it's at any of the domains, or is any of
    // the addresses.
    private const array SENDER_KEYS = ['domains', 'addresses'];


    /**
     * Read the configuration.
     *
     * @param mixed $data - the decoded YAML or JSON.
     *
     * @param ProviderDefinitionCollection $current - the providers being replaced, to take the secrets marked
     * "<unchanged>" from, and the id of a provider given without one. Empty if there aren't any (or they couldn't be
     * read).
     *
     * @return ProvidersConfig
     *
     * @throws InvalidInputException - if anything is missing, unknown or invalid. The message says where.
     */
    public static function parse(mixed $data, ProviderDefinitionCollection $current) : ProvidersConfig
    {
        if (!is_array($data))
        {
            throw new InvalidInputException('The configuration must have a "mode" and a list of "providers".');
        }

        self::assertKnownKeys($data, ['mode', 'providers'], 'The configuration');
        $modeName = $data['mode'] ?? null;
        $mode = is_string($modeName) ? ProviderMode::tryFrom($modeName) : null;

        if ($mode === null)
        {
            $modes = implode(', ', array_column(ProviderMode::cases(), 'value'));
            throw new InvalidInputException("\"mode\" must be one of: {$modes}.");
        }

        $providers = $data['providers'] ?? null;

        if (!is_array($providers) || !array_is_list($providers) || count($providers) === 0)
        {
            throw new InvalidInputException('"providers" must be a list of at least one provider.');
        }

        $definitions = [];

        foreach ($providers as $index => $provider)
        {
            $definitions[] = self::parseProvider($provider, "providers[{$index}]", $current);
        }

        return new ProvidersConfig($mode, ...$definitions);
    }


    /**
     * Get the configuration as a plain array (e.g. for JSON), in the same structure as settings.yaml's providers
     * section.
     *
     * @param ProvidersConfig $config - the configuration.
     *
     * @param SecretOutput $secretOutput - how to write out the secrets that aren't references.
     *
     * @return array
     */
    public static function toArray(ProvidersConfig $config, SecretOutput $secretOutput) : array
    {
        $providers = [];

        foreach ($config->getProviders() as $provider)
        {
            /* @var $provider ProviderDefinition */
            $output = [
                'id' => $provider->getId()->getValue(),
                'name' => $provider->getName(),
                'type' => $provider->getType()->value,
                'enabled' => $provider->isEnabled(),
            ];

            foreach ($provider->getSettings()->getSettings() as $setting)
            {
                /* @var $setting ConfigSetting */
                $output[$setting->getName()] = self::presentSetting($setting, $secretOutput);
            }

            $senders = $provider->getAllowedSenders();

            if ($senders instanceof AddressRuleSet)
            {
                $output['allowed_senders'] = self::presentAllowedSenders($senders);
            }

            $output['rate_limits'] = [];

            foreach ($provider->getRateLimits()->getLimits() as $limit)
            {
                /* @var $limit RateLimit */
                $output['rate_limits'][] = [
                    'unit' => $limit->getUnit()->value,
                    'max' => $limit->getMax(),
                    'period_seconds' => $limit->getPeriodSeconds(),
                ];
            }

            $providers[] = $output;
        }

        return ['mode' => $config->getMode()->value, 'providers' => $providers];
    }


    /**
     * Get the configuration as the text of settings.yaml's providers section (without its key or indentation), with
     * comments explaining it.
     *
     * @param ProvidersConfig $config - the configuration.
     *
     * @param SecretOutput $secretOutput - how to write out the secrets that aren't references.
     *
     * @return string
     */
    public static function toYaml(ProvidersConfig $config, SecretOutput $secretOutput) : string
    {
        $data = self::toArray($config, $secretOutput);
        $modes = implode(', ', array_column(ProviderMode::cases(), 'value'));

        $lines = [
            '# The providers that ' . SERVICE_NAME . ' sends email through, and how emails are shared between them.',
            '# They can be edited here, on the Providers page of the web interface, or through the API, which check',
            '# them before they are saved.',
            '#',
            "# mode: one of {$modes}.",
        ];

        foreach (ProviderMode::cases() as $mode)
        {
            $lines[] = "#   {$mode->value}: {$mode->getDescription()}";
        }

        $lines[] = 'mode: ' . self::quote($data['mode']);
        $lines[] = '';
        $lines[] = 'providers:';

        foreach ($data['providers'] as $index => $provider)
        {
            $prefix = '  - ';

            foreach ($provider as $key => $value)
            {
                if ($key === 'allowed_senders')
                {
                    $lines[] = '    allowed_senders:';

                    foreach ($value as $listKey => $listValues)
                    {
                        $quoted = [];

                        foreach ($listValues as $listValue)
                        {
                            $quoted[] = self::quote($listValue);
                        }

                        $lines[] = "      {$listKey}: [" . implode(', ', $quoted) . ']';
                    }
                }
                elseif ($key === 'rate_limits')
                {
                    $lines[] = '    rate_limits:' . ((count($value) === 0) ? ' []' : '');

                    foreach ($value as $limit)
                    {
                        $lines[] = '      - unit: ' . self::quote($limit['unit']);
                        $lines[] = '        max: ' . $limit['max'];
                        $lines[] = '        period_seconds: ' . $limit['period_seconds'];
                    }
                }
                else
                {
                    $lines[] = $prefix . $key . ': ' . self::quote($value);
                }

                $prefix = '    ';
            }

            $lines[] = '';
        }

        return implode("\n", $lines);
    }


    /**
     * Read one provider.
     *
     * @throws InvalidInputException - if anything is missing, unknown or invalid.
     */
    private static function parseProvider(
        mixed $data,
        string $where,
        ProviderDefinitionCollection $current
    ) : ProviderDefinition
    {
        if (!is_array($data) || array_is_list($data))
        {
            throw new InvalidInputException("{$where} must be a provider, with a name and type.");
        }

        $name = $data['name'] ?? null;
        $idValue = $data['id'] ?? null;
        $typeName = $data['type'] ?? null;
        $type = is_string($typeName) ? ProviderType::tryFrom($typeName) : null;
        $enabled = $data['enabled'] ?? true;

        if (!is_string($name))
        {
            throw new InvalidInputException("{$where} must have a \"name\".");
        }

        $where = "Provider \"{$name}\"";

        if ($idValue !== null && !is_string($idValue))
        {
            throw new InvalidInputException("{$where}: \"id\" must be a UUID, or left out to have one made for it.");
        }

        if ($type === null)
        {
            $types = implode(', ', array_column(ProviderType::cases(), 'value'));
            throw new InvalidInputException("{$where} must have a \"type\" of: {$types}.");
        }

        if (!is_bool($enabled))
        {
            throw new InvalidInputException("{$where}: \"enabled\" must be true or false.");
        }

        $previous = self::findPrevious($current, $name, $type);

        try
        {
            $settings = match ($type) {
                ProviderType::SENDGRID => self::parseSendGridSettings($data, $where, $previous),
                ProviderType::MAILGUN => self::parseMailgunSettings($data, $where, $previous),
                ProviderType::POSTMARK => self::parsePostmarkSettings($data, $where, $previous),
                ProviderType::BREVO => self::parseBrevoSettings($data, $where, $previous),
                ProviderType::MAILJET => self::parseMailjetSettings($data, $where, $previous),
                ProviderType::MAILERSEND => self::parseMailerSendSettings($data, $where, $previous),
                ProviderType::MAILTRAP => self::parseMailtrapSettings($data, $where, $previous),
                ProviderType::CUSTOM => self::parseSmtpSettings($data, $where, $previous),
            };

            $id = ($idValue === null) ? self::findCurrentId($current, $name) : new ProviderId($idValue);
            $rateLimits = self::parseRateLimits($data['rate_limits'] ?? []);
            $hasSenders = array_key_exists('allowed_senders', $data);
            $senders = $hasSenders ? self::parseAllowedSenders($data['allowed_senders']) : new AnyAddress();
            $definition = new ProviderDefinition($id, $name, $enabled, $settings, $rateLimits, $senders);
        }
        catch (InvalidInputException $e)
        {
            $message = str_starts_with($e->getMessage(), $where) ? $e->getMessage() : "{$where}: {$e->getMessage()}";
            throw new InvalidInputException($message);
        }

        return $definition;
    }


    /**
     * @throws InvalidInputException
     */
    private static function parseSendGridSettings(
        array $data,
        string $where,
        PreviousSettings $previous
    ) : SendGridSettings
    {
        self::assertKnownKeys($data, [...self::COMMON_KEYS, 'api_key', 'webhook_public_key'], $where);

        return new SendGridSettings(
            self::readValue($data, 'api_key', true, true, $previous),
            self::readValue($data, 'webhook_public_key', false, false, $previous),
        );
    }


    /**
     * @throws InvalidInputException
     */
    private static function parseMailgunSettings(
        array $data,
        string $where,
        PreviousSettings $previous
    ) : MailgunSettings
    {
        $keys = ['region', 'smtp_username', 'smtp_password', 'api_key', 'webhook_signing_key'];
        self::assertKnownKeys($data, [...self::COMMON_KEYS, ...$keys], $where);
        $region = MailgunRegion::tryFrom((string)($data['region'] ?? ''));

        if ($region === null)
        {
            $regions = implode(' or ', array_column(MailgunRegion::cases(), 'value'));
            throw new InvalidInputException("\"region\" must be {$regions}, the region its domain is in.");
        }

        return new MailgunSettings(
            $region,
            self::readValue($data, 'smtp_username', true, false, $previous),
            self::readValue($data, 'smtp_password', true, true, $previous),
            self::readValue($data, 'api_key', false, true, $previous),
            self::readValue($data, 'webhook_signing_key', false, true, $previous),
        );
    }


    /**
     * @throws InvalidInputException
     */
    private static function parsePostmarkSettings(
        array $data,
        string $where,
        PreviousSettings $previous
    ) : PostmarkSettings
    {
        $keys = ['server_token', 'message_stream', 'account_token', 'webhook_secret'];
        self::assertKnownKeys($data, [...self::COMMON_KEYS, ...$keys], $where);
        $stream = $data['message_stream'] ?? 'outbound';

        if (!is_string($stream))
        {
            throw new InvalidInputException('"message_stream" must be a stream\'s ID, e.g. "outbound".');
        }

        return new PostmarkSettings(
            self::readValue($data, 'server_token', true, true, $previous),
            $stream,
            self::readValue($data, 'account_token', false, true, $previous),
            self::readValue($data, 'webhook_secret', false, true, $previous),
        );
    }


    /**
     * @throws InvalidInputException
     */
    private static function parseBrevoSettings(array $data, string $where, PreviousSettings $previous) : BrevoSettings
    {
        $keys = ['smtp_login', 'smtp_key', 'api_key', 'webhook_secret'];
        self::assertKnownKeys($data, [...self::COMMON_KEYS, ...$keys], $where);

        return new BrevoSettings(
            self::readValue($data, 'smtp_login', true, false, $previous),
            self::readValue($data, 'smtp_key', true, true, $previous),
            self::readValue($data, 'api_key', false, true, $previous),
            self::readValue($data, 'webhook_secret', false, true, $previous),
        );
    }


    /**
     * @throws InvalidInputException
     */
    private static function parseMailjetSettings(
        array $data,
        string $where,
        PreviousSettings $previous
    ) : MailjetSettings
    {
        self::assertKnownKeys($data, [...self::COMMON_KEYS, 'api_key', 'secret_key', 'webhook_secret'], $where);

        return new MailjetSettings(
            self::readValue($data, 'api_key', true, false, $previous),
            self::readValue($data, 'secret_key', true, true, $previous),
            self::readValue($data, 'webhook_secret', false, true, $previous),
        );
    }


    /**
     * @throws InvalidInputException
     */
    private static function parseMailerSendSettings(
        array $data,
        string $where,
        PreviousSettings $previous
    ) : MailerSendSettings
    {
        $keys = ['smtp_username', 'smtp_password', 'api_token', 'webhook_secret'];
        self::assertKnownKeys($data, [...self::COMMON_KEYS, ...$keys], $where);

        return new MailerSendSettings(
            self::readValue($data, 'smtp_username', true, false, $previous),
            self::readValue($data, 'smtp_password', true, true, $previous),
            self::readValue($data, 'api_token', false, true, $previous),
            self::readValue($data, 'webhook_secret', false, true, $previous),
        );
    }


    /**
     * @throws InvalidInputException
     */
    private static function parseMailtrapSettings(
        array $data,
        string $where,
        PreviousSettings $previous
    ) : MailtrapSettings
    {
        self::assertKnownKeys($data, [...self::COMMON_KEYS, 'smtp_token', 'api_token', 'webhook_secret'], $where);

        return new MailtrapSettings(
            self::readValue($data, 'smtp_token', true, true, $previous),
            self::readValue($data, 'api_token', false, true, $previous),
            self::readValue($data, 'webhook_secret', false, true, $previous),
        );
    }


    /**
     * @throws InvalidInputException
     */
    private static function parseSmtpSettings(array $data, string $where, PreviousSettings $previous) : SmtpSettings
    {
        $allowed = [...self::COMMON_KEYS, 'host', 'port', 'security', 'username', 'password', 'dkim_selector'];
        self::assertKnownKeys($data, $allowed, $where);
        $securityName = $data['security'] ?? null;
        $security = is_string($securityName) ? SmtpSecurity::tryFrom($securityName) : null;

        if (!is_int($data['port'] ?? null))
        {
            throw new InvalidInputException('"port" must be a number, e.g. 587.');
        }

        if ($security === null)
        {
            throw new InvalidInputException('"security" must be tls (STARTTLS), ssl (implicit TLS) or none.');
        }

        // A saved password kept as "<unchanged>" can't go to a different server without being entered again.
        $isKept = ($previous->exists() && ($data['password'] ?? '') === self::UNCHANGED);
        $changed = $isKept ? SmtpSettings::findServerChanges($previous->getSettings(), $data) : [];

        if (count($changed) > 0)
        {
            throw new InvalidInputException(SmtpSettings::describePasswordNeeded(...$changed));
        }

        $username = self::readValue($data, 'username', false, false, $previous);
        $password = self::readValue($data, 'password', false, true, $previous);

        if ($username->toConfigString() !== '' && $password->toConfigString() === '')
        {
            throw new InvalidInputException('A "password" is needed when there\'s a "username".');
        }

        $dkimSelector = $data['dkim_selector'] ?? '';

        if (!is_string($dkimSelector))
        {
            throw new InvalidInputException('"dkim_selector" must be text, e.g. "dkim".');
        }

        $host = self::readValue($data, 'host', true, false, $previous);
        return new SmtpSettings($host, $data['port'], $security, $username, $password, trim($dkimSelector));
    }


    /**
     * @throws InvalidInputException
     */
    private static function parseRateLimits(mixed $data) : RateLimitSet
    {
        if (!is_array($data) || !array_is_list($data))
        {
            throw new InvalidInputException('"rate_limits" must be a list (or [] for no limit).');
        }

        $limits = [];

        foreach ($data as $index => $item)
        {
            $unit = is_array($item) ? RateLimitUnit::tryFrom((string)($item['unit'] ?? '')) : null;

            if (
                   $unit === null
                || !is_int($item['max'] ?? null)
                || !is_int($item['period_seconds'] ?? null)
                || count(array_diff(array_keys($item), ['unit', 'max', 'period_seconds'])) > 0
            )
            {
                $msg =
                    "rate_limits[{$index}] must have a \"unit\" (emails or recipients), and whole number \"max\" and"
                    . ' "period_seconds".';

                throw new InvalidInputException($msg);
            }

            $limits[] = new RateLimit($unit, $item['max'], $item['period_seconds']);
        }

        return new RateLimitSet(...$limits);
    }


    /**
     * Read a provider's "allowed_senders": its "domains" and "addresses" lists, either of which may be left out or
     * empty, but not both (to take any sender, "allowed_senders" is left out).
     *
     * @param mixed $data - the decoded "allowed_senders".
     *
     * @return AddressRuleSet
     *
     * @throws InvalidInputException - if it isn't valid, saying where.
     */
    private static function parseAllowedSenders(mixed $data) : AddressRuleSet
    {
        if (!is_array($data) || ($data !== [] && array_is_list($data)))
        {
            $msg =
                '"allowed_senders" must have a list of "domains", a list of "addresses", or both (it takes a sender'
                . ' at any of the domains, or with any of the addresses). Leave it out to take any sender.';

            throw new InvalidInputException($msg);
        }

        self::assertKnownKeys($data, self::SENDER_KEYS, '"allowed_senders"');
        $rules = [];

        foreach (self::SENDER_KEYS as $key)
        {
            $values = $data[$key] ?? [];

            if (!is_array($values) || !array_is_list($values))
            {
                throw new InvalidInputException("\"allowed_senders.{$key}\" must be a list.");
            }

            foreach ($values as $index => $value)
            {
                if (!is_string($value))
                {
                    throw new InvalidInputException("allowed_senders.{$key}[{$index}] must be a string.");
                }

                try
                {
                    $rules[] = ($key === 'domains')
                        ? AddressRule::domain($value)
                        : AddressRule::address(new EmailAddress($value));
                }
                catch (InvalidInputException $e)
                {
                    throw new InvalidInputException("allowed_senders.{$key}[{$index}]: {$e->getMessage()}");
                }
            }
        }

        if (count($rules) === 0)
        {
            $msg =
                '"allowed_senders" has no domains or addresses, so it would take no sender at all. To take any sender,'
                . ' leave "allowed_senders" out.';

            throw new InvalidInputException($msg);
        }

        return new AddressRuleSet(...$rules);
    }


    /**
     * Get a provider's sender restriction as its "allowed_senders": the domains and the addresses, each a list.
     * @param AddressRuleSet $senders - the restriction.
     * @return array{domains: string[], addresses: string[]}
     */
    private static function presentAllowedSenders(AddressRuleSet $senders) : array
    {
        $output = ['domains' => [], 'addresses' => []];

        foreach ($senders->getRules() as $rule)
        {
            /* @var $rule AddressRule */
            $key = ($rule->getKind() === AddressRuleKind::DOMAIN) ? 'domains' : 'addresses';
            $output[$key][] = $rule->getValue();
        }

        return $output;
    }


    /**
     * Read a setting's value.
     *
     * @param array $data - the provider's data.
     *
     * @param string $key - the setting's name.
     *
     * @param bool $isRequired - whether it must be given, and not be empty.
     *
     * @param bool $isSecret - whether it's a secret, so may be "<unchanged>".
     *
     * @param PreviousSettings $previous - the settings of the provider being replaced, for "<unchanged>".
     *
     * @return ConfigValue
     *
     * @throws InvalidInputException - if it's missing, isn't a string, is "<unchanged>" with no previous value, or is a
     * reference to an environment variable (see ConfigValue).
     */
    private static function readValue(
        array $data,
        string $key,
        bool $isRequired,
        bool $isSecret,
        PreviousSettings $previous
    ) : ConfigValue
    {
        $value = $data[$key] ?? '';

        if (!is_string($value) || ($isRequired && trim($value) === ''))
        {
            throw new InvalidInputException("\"{$key}\" " . ($isRequired ? 'must be set.' : 'must be text.'));
        }

        if ($isSecret && $value === self::UNCHANGED)
        {
            try
            {
                $result = $previous->getValue($key);
            }
            catch (NotFoundException)
            {
                $msg =
                    "\"{$key}\" is \"" . self::UNCHANGED . "\", but there's no existing value to keep. Give its"
                    . ' value.';

                throw new InvalidInputException($msg);
            }
        }
        else
        {
            $result = ConfigValue::createFromString($value);
        }

        return $result;
    }


    /**
     * @throws InvalidInputException - if the data has a key that isn't allowed.
     */
    private static function assertKnownKeys(array $data, array $allowed, string $where) : void
    {
        $unknown = array_diff(array_keys($data), $allowed);

        if (count($unknown) > 0)
        {
            $msg =
                "{$where} has unknown settings: " . implode(', ', $unknown) . '. The settings it can have are: '
                . implode(', ', $allowed) . '.';

            throw new InvalidInputException($msg);
        }
    }


    /**
     * Find the settings of the provider a new one replaces: the current one with the same name and type.
     *
     * @return PreviousSettings - its settings, or none() if there isn't one.
     */
    private static function findPrevious(
        ProviderDefinitionCollection $current,
        string $name,
        ProviderType $type
    ) : PreviousSettings
    {
        $previous = PreviousSettings::none();

        foreach ($current as $candidate)
        {
            /* @var $candidate ProviderDefinition */
            if ($candidate->getName() === $name && $candidate->getType() === $type)
            {
                $previous = PreviousSettings::from($candidate);
            }
        }

        return $previous;
    }


    /**
     * Get the id for a provider given without one: the id of the current provider with the same name, so it stays
     * linked to its email tokens, or a new one if there isn't one.
     *
     * @param ProviderDefinitionCollection $current - the providers being replaced.
     *
     * @param string $name - the provider's name.
     *
     * @return ProviderId
     */
    private static function findCurrentId(ProviderDefinitionCollection $current, string $name) : ProviderId
    {
        $currentIds = [];

        foreach ($current as $candidate)
        {
            /* @var $candidate ProviderDefinition */
            if ($candidate->getName() === $name)
            {
                $currentIds[] = $candidate->getId();
            }
        }

        // Names are unique, so there's at most one.
        return (count($currentIds) > 0) ? $currentIds[0] : ProviderId::createNew();
    }


    /**
     * Get a setting's value for output.
     *
     * @return string|int
     */
    private static function presentSetting(ConfigSetting $setting, SecretOutput $secretOutput) : string|int
    {
        $value = $setting->getValue();
        $isSecretValue = ($setting->isSecret() && $value instanceof ConfigValue && $value->toConfigString() !== '');

        $isMasked = ($isSecretValue && $secretOutput === SecretOutput::MASKED);

        $output = match (true) {
            $value instanceof ConfigValue && $isMasked => self::UNCHANGED,
            $value instanceof ConfigValue => $value->toConfigString(),
            default => $value,
        };

        return $output;
    }


    /**
     * Quote a value for YAML. A JSON string is a valid YAML double-quoted string, so json_encode() is used.
     */
    private static function quote(string|int|bool $value) : string
    {
        return is_string($value)
            ? json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            : var_export($value, true);
    }
}
