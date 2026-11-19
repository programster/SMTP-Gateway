<?php

/*
 * Carries an existing install's settings over to settings.yaml, once, on the first start after upgrading: the
 * providers from providers.yaml, and the settings that used to be environment variables (TIMEZONE, REQUIRE_MFA,
 * TRUSTED_PROXIES, SYSTEM_EMAIL_FROM, SYNC_WAIT_SECONDS, the EMAIL_*_RETENTION_DAYS, LOG_RETENTION_DAYS and
 * GOOGLE_SSO_*). Only the sections with something set are written, so everything else keeps its default. Then
 * providers.yaml is renamed to providers.yaml.migrated, as it's no longer used.
 *
 * It does nothing once settings.yaml exists, or when there's nothing to carry over (a fresh install, which starts with
 * no providers, to be added in the web interface). Run on every start up, before the workers start. It's never fatal:
 * if something can't be carried over, it says why, and leaves everything as it was, to be tried again on the next
 * start once it's fixed.
 */

declare(strict_types = 1);

require_once(__DIR__ . '/../bootstrap.php');


function main() : void
{
    $path = SettingsFile::getPath();
    $oldProvidersPath = Config::getEnv('PROVIDERS_CONFIG_FILE', dirname($path) . '/providers.yaml');

    try
    {
        if (file_exists($path))
        {
            print "{$path} exists, so there's nothing to carry over." . PHP_EOL;
        }
        else
        {
            $sections = readOldSettings($oldProvidersPath);

            if (count($sections) === 0)
            {
                print "There are no settings to carry over, so {$path} will be created by the first save." . PHP_EOL;
            }
            else
            {
                SettingsFile::saveWholeFile(SettingsFile::formatFile(...$sections), SettingsFile::getFileVersion());
                print "Created {$path}, from the settings of an earlier version." . PHP_EOL;

                if (file_exists($oldProvidersPath) && !rename($oldProvidersPath, "{$oldProvidersPath}.migrated"))
                {
                    fwrite(STDERR, "WARNING: Couldn't rename {$oldProvidersPath}, which is no longer used." . PHP_EOL);
                }
            }
        }
    }
    catch (InvalidInputException | ConfigurationException | ConflictException $e)
    {
        // Not fatal: the web interface still starts, so the settings can be set there.
        $msg = "The settings of an earlier version couldn't be carried over to {$path}: {$e->getMessage()}";
        fwrite(STDERR, "WARNING: {$msg}" . PHP_EOL);
    }
}


/**
 * Read the earlier version's settings, as each section of settings.yaml that has something set.
 *
 * @param string $oldProvidersPath - where providers.yaml was.
 *
 * @return list<string> - each section, with its key (see SettingsFile::formatSection()).
 *
 * @throws InvalidInputException - if a setting isn't valid.
 * @throws ConfigurationException - if providers.yaml can't be read.
 */
function readOldSettings(string $oldProvidersPath) : array
{
    $sections = [];

    if (isAnyEnvSet('TIMEZONE'))
    {
        $format = DateTimeFormat::createDefault();
        $general = GeneralSettings::createUsingTimezoneName(Config::getEnv('TIMEZONE'), $format);
        $yaml = ServiceSettingsParser::generalToYaml($general);
        $sections[] = SettingsFile::formatSection(SettingsSection::GENERAL, $yaml);
    }

    if (isAnyEnvSet('REQUIRE_MFA'))
    {
        $security = new SecuritySettings(readBool('REQUIRE_MFA', true), PasswordPolicy::createDefault());
        $yaml = ServiceSettingsParser::securityToYaml($security);
        $sections[] = SettingsFile::formatSection(SettingsSection::SECURITY, $yaml);
    }

    if (isAnyEnvSet('TRUSTED_PROXIES'))
    {
        $proxies = new ProxySettings(false, readTrustedProxies());
        $yaml = ServiceSettingsParser::proxiesToYaml($proxies);
        $sections[] = SettingsFile::formatSection(SettingsSection::PROXIES, $yaml);
    }

    if (isAnyEnvSet('SYSTEM_EMAIL_FROM', 'SYNC_WAIT_SECONDS'))
    {
        // It can now be at most 60 seconds, so a longer wait is cut to that.
        $waitSeconds = readInt('SYNC_WAIT_SECONDS', SendingSettings::createDefault()->getSyncWaitSeconds());
        $waitSeconds = min($waitSeconds, SendingSettings::MAX_SYNC_WAIT_SECONDS);
        $sending = new SendingSettings(Config::getEnv('SYSTEM_EMAIL_FROM'), $waitSeconds);
        $yaml = ServiceSettingsParser::sendingToYaml($sending);
        $sections[] = SettingsFile::formatSection(SettingsSection::SENDING, $yaml);
    }

    $retentionNames = [
        'EMAIL_RECORD_RETENTION_DAYS',
        'EMAIL_CONTENT_RETENTION_DAYS',
        'EMAIL_ATTACHMENT_RETENTION_DAYS',
        'LOG_RETENTION_DAYS',
    ];

    if (isAnyEnvSet(...$retentionNames))
    {
        $policy = EmailRetentionPolicy::createFromSettings(
            Config::getEnv('EMAIL_RECORD_RETENTION_DAYS', '90'),
            Config::getEnv('EMAIL_CONTENT_RETENTION_DAYS', '-1'),
            Config::getEnv('EMAIL_ATTACHMENT_RETENTION_DAYS', '-1')
        );

        $retention = new RetentionSettings($policy, readInt('LOG_RETENTION_DAYS', 90));
        $yaml = ServiceSettingsParser::retentionToYaml($retention);
        $sections[] = SettingsFile::formatSection(SettingsSection::RETENTION, $yaml);
    }

    if (isAnyEnvSet('GOOGLE_SSO_ENABLED', 'GOOGLE_SSO_CLIENT_ID', 'GOOGLE_SSO_CLIENT_SECRET', 'GOOGLE_SSO_BYPASS_MFA'))
    {
        $bypassName = strtolower(Config::getEnv('GOOGLE_SSO_BYPASS_MFA', SsoMfaBypass::NEVER->value));
        $bypass = SsoMfaBypass::tryFrom($bypassName);

        if ($bypass === null)
        {
            throw new InvalidInputException("GOOGLE_SSO_BYPASS_MFA can't be \"{$bypassName}\".");
        }

        // The secret is written out in full, as the file is allowed to hold secrets, and isn't committed (config/ is
        // ignored by git). Exporting it from the web interface replaces it with a reference.
        $googleSso = new GoogleSsoConfig(
            readBool('GOOGLE_SSO_ENABLED', false),
            Config::getEnv('GOOGLE_SSO_CLIENT_ID'),
            ConfigValue::createFromString(Config::getEnv('GOOGLE_SSO_CLIENT_SECRET')),
            $bypass
        );

        $yaml = GoogleSsoConfigParser::toYaml($googleSso, SecretOutput::AS_IS);
        $sections[] = SettingsFile::formatSection(SettingsSection::GOOGLE_SSO, $yaml);
    }

    if (file_exists($oldProvidersPath))
    {
        $providers = @file_get_contents($oldProvidersPath);

        if ($providers === false)
        {
            throw new ConfigurationException("{$oldProvidersPath} can't be read.");
        }

        // Kept as it's written, comments and all.
        $sections[] = SettingsFile::formatSection(SettingsSection::PROVIDERS, $providers);
    }

    return $sections;
}


/**
 * Whether any of the environment variables is set (to something other than an empty string).
 * @param string ...$names
 * @return bool
 */
function isAnyEnvSet(string ...$names) : bool
{
    $isAnySet = false;

    foreach ($names as $name)
    {
        $isAnySet = $isAnySet || Config::getEnv($name) !== '';
    }

    return $isAnySet;
}


/**
 * @throws InvalidInputException - if it's set to anything other than "true" or "false".
 */
function readBool(string $name, bool $default) : bool
{
    $value = strtolower(trim(Config::getEnv($name, $default ? 'true' : 'false')));

    if ($value !== 'true' && $value !== 'false')
    {
        throw new InvalidInputException("{$name} must be \"true\" or \"false\", not \"{$value}\".");
    }

    return $value === 'true';
}


/**
 * @throws InvalidInputException - if it's set to anything other than a whole number.
 */
function readInt(string $name, int $default) : int
{
    $value = trim(Config::getEnv($name, (string)$default));

    if (preg_match('/^-?[0-9]+$/', $value) !== 1)
    {
        throw new InvalidInputException("{$name} must be a whole number, not \"{$value}\".");
    }

    return (int)$value;
}


/**
 * @throws InvalidInputException - if one of them isn't a valid IP address or range.
 */
function readTrustedProxies() : IpNetworkCollection
{
    $proxies = [];

    foreach (preg_split('/[\s,]+/', Config::getEnv('TRUSTED_PROXIES'), -1, PREG_SPLIT_NO_EMPTY) as $part)
    {
        $proxies[] = new IpNetwork($part);
    }

    return new IpNetworkCollection(...$proxies);
}


main();
