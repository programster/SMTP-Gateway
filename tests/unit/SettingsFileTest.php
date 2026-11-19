<?php

/*
 * settings.yaml (SettingsFile and Settings): the defaults with no file, saving each section on its own while keeping
 * the others exactly as they're written, conflicts within a section but not between them, what happens when a section
 * (or the whole file) is invalid, exporting without secrets, and the mistakes each setting refuses.
 *
 * Each check uses a file of its own, in a temporary folder, by pointing SETTINGS_FILE at it.
 */

declare(strict_types = 1);

require_once __DIR__ . '/TestSuite.php';

$suite = new TestSuite('Settings file');
$folder = sys_get_temp_dir() . '/settings-test-' . bin2hex(random_bytes(4));
mkdir($folder);
$count = 0;
$emptyVersion = hash('sha256', '');

/**
 * Point SETTINGS_FILE at a new file (with the contents given, if any), and get its path.
 */
$useFile = function (string $contents = '', bool $isCreated = true) use ($folder, &$count) : string {
    $count++;
    $path = "{$folder}/settings-{$count}.yaml";
    putenv("SETTINGS_FILE={$path}");

    if ($isCreated)
    {
        file_put_contents($path, $contents);
    }

    return $path;
};

$mailpitProviders = <<<'YAML'
    mode: "overflow"
    providers:
      - name: "mailpit"
        type: "custom"
        enabled: true
        host: "mailpit"
        port: 1025
        security: "none"
        username: "someone"
        password: "plaintext-secret"
        rate_limits: []
    YAML;

$loadProviders = function () : void {
    SettingsFile::loadProviders();
};

// No file: no providers yet, and every other setting has its default.
$useFile('', false);
$suite->checkThrows('no file means no providers yet', ProvidersNotConfiguredException::class, $loadProviders);
$settings = Settings::get();
$retention = $settings->getRetention();

$isDefault = (
       $settings->getTimezone()->getName() === 'UTC'
    && $settings->isMfaRequired()
    && count($settings->getTrustedProxies()) === 0
    && $settings->getSyncWaitSeconds() === 5
    && $settings->getSending()->hasSystemEmailFrom() === false
    && $retention->getEmailRetention()->getRecordDays() === 90
    && $retention->getEmailRetention()->getContentSetting() === -1
    && $retention->getEmailRetention()->getAttachmentSetting() === -1
    && $retention->getLogDays() === 90
    && $settings->isGoogleSsoEnabled() === false
    && count($settings->getProblems()) === 0
);

$suite->check('with no file, every setting has its default', $isDefault);
$isEmptyVersion = (SettingsFile::getSectionVersion(SettingsSection::PROVIDERS) === $emptyVersion);
$suite->check('a missing section\'s version is the hash of nothing', $isEmptyVersion);

// The first save creates the file, and its lock isn't next to it.
$path = $useFile('', false);
SettingsFile::saveProviders(SettingsFile::parseProvidersYaml($mailpitProviders), $emptyVersion);
$isCreated = (is_file($path) && SettingsFile::loadProviders()->getMode() === ProviderMode::OVERFLOW);
$suite->check('the first save creates the file', $isCreated);
$permissions = fileperms($path) & 0777;
$suite->check('it can be written by the group', $permissions === 0664, sprintf('%o', $permissions));
$others = implode(', ', array_diff(scandir($folder), ['.', '..', basename($path)]));
$isLeftOut = !str_contains($others, '.lock') && !str_contains($others, '.tmp');
$suite->check('no lock or temporary file is left next to it', $isLeftOut, $others);

// Saving one section keeps the others exactly as they're written, comments and all.
$indentedProviders = implode("\n", array_map('SettingsFileTestIndent', explode("\n", $mailpitProviders)));

$useFile(<<<YAML
    # A comment at the top, which is replaced by the file's explanation.
    general:
      # The office's time zone.
      timezone: "Europe/London"

    providers:
      # Kept as it is, when another section is saved.
    {$indentedProviders}
    YAML);

$providersBefore = SettingsFile::readSectionText(SettingsSection::PROVIDERS);
$sendingVersion = SettingsFile::getSectionVersion(SettingsSection::SENDING);
SettingsFile::saveSending(new SendingSettings('noreply@example.org', 10), $sendingVersion);
$saved = (string)file_get_contents((string)getenv('SETTINGS_FILE'));

$isKept = (
       str_contains($saved, "  # The office's time zone.\n  timezone: \"Europe/London\"")
    && str_contains($saved, '# Kept as it is, when another section is saved.')
    && SettingsFile::readSectionText(SettingsSection::PROVIDERS) === $providersBefore
);

$suite->check('saving a section keeps the others as they\'re written', $isKept, $saved);
$sending = Settings::get()->getSending();
$isSaved = ($sending->getSyncWaitSeconds() === 10 && (string)$sending->getSystemEmailFrom() === 'noreply@example.org');
$suite->check('and saves its own', $isSaved);
$isUnindented = (str_starts_with($providersBefore, '# Kept as it is') && str_contains($providersBefore, "\nmode: "));
$suite->check('a section is shown as written, without its key or indentation', $isUnindented, $providersBefore);

// A change to one section doesn't conflict with a change to another, but does with one to the same section.
$generalVersion = SettingsFile::getSectionVersion(SettingsSection::GENERAL);
$securityVersion = SettingsFile::getSectionVersion(SettingsSection::SECURITY);
$proxiesVersion = SettingsFile::getSectionVersion(SettingsSection::PROXIES);
$paris = GeneralSettings::createUsingTimezoneName('Europe/Paris', DateTimeFormat::createDefault());
SettingsFile::saveGeneral($paris, $generalVersion);
SettingsFile::saveSecurity(new SecuritySettings(false, PasswordPolicy::createDefault()), $securityVersion);
$proxies = new ProxySettings(true, new IpNetworkCollection(new IpNetwork('172.16.0.0/12')));
SettingsFile::saveProxies($proxies, $proxiesVersion);
$isBothSaved = (!Settings::get()->isMfaRequired() && Settings::get()->getTimezone()->getName() === 'Europe/Paris');
$suite->check('saving another section doesn\'t conflict', $isBothSaved);
$saved = Settings::get()->getProxies();
$isSaved = ($saved->isBehindCloudflare() && (string)$saved->getTrustedProxies()[0] === '172.16.0.0/12');
$suite->check('the proxies are saved in a section of their own', $isSaved);

$saveFromOldVersion = function () use ($generalVersion) : void {
    $utc = GeneralSettings::createUsingTimezoneName('UTC', DateTimeFormat::createDefault());
    SettingsFile::saveGeneral($utc, $generalVersion);
};

$suite->checkThrows('saving a section from an old version conflicts', ConflictException::class, $saveFromOldVersion);

// Settings (and sections) that are left out have their defaults.
$useFile("sending:\n  system_email_from: \"a@example.org\"\nretention:\n  email_content_days: 30\nsecurity:\n");
$settings = Settings::get();
$retention = $settings->getRetention();

$isDefaulted = (
       $settings->getSyncWaitSeconds() === 5
    && $retention->getEmailRetention()->getRecordDays() === 90
    && $retention->getEmailRetention()->getContentSetting() === 30
    && $retention->getLogDays() === 90
    && $settings->isMfaRequired()
    && count($settings->getProblems()) === 0
);

$suite->check('settings left out have their defaults', $isDefaulted, implode(' | ', $settings->getProblems()));

// An invalid section has its (safe) defaults, and says why. An invalid retention section deletes nothing.
$useFile("security:\n  require_mfa: false\n  trusted_proxies: [\"0.0.0.0/0\"]\nretention:\n  email_content_days: 91\n");
$settings = Settings::get();
$isDefaulted = ($settings->isMfaRequired() && count($settings->getTrustedProxies()) === 0);
$suite->check('an invalid section has its defaults', $isDefaulted);
$problems = implode(' | ', $settings->getProblems());
$suite->check('and the problem is listed', count($settings->getProblems()) === 2, $problems);

$getRetention = function () use ($settings) : void {
    $settings->getRetention();
};

$suite->checkThrows('an invalid retention section deletes nothing', ConfigurationException::class, $getRetention);
$suite->check('and says content is kept for now', str_contains($settings->describeContentRetention(), 'kept for now'));

// A file that isn't valid YAML, or has a section that doesn't exist, can only be fixed as a whole.
$saveGeneral = function () use ($emptyVersion) : void {
    SettingsFile::saveGeneral(GeneralSettings::createDefault(), $emptyVersion);
};

$unreadableFiles = ['not YAML' => "providers: [\n", 'an unknown section' => "providers_typo:\n  mode: overflow\n"];

foreach ($unreadableFiles as $what => $text)
{
    $useFile($text);
    $suite->checkThrows("a file that's {$what} can't be read", SettingsFileInvalidException::class, $loadProviders);
    $isDefaulted = (Settings::get()->isMfaRequired() && count(Settings::get()->getProblems()) === 1);
    $suite->check("and every other setting has its default ({$what})", $isDefaulted);
    $name = "and no section can be saved on its own ({$what})";
    $suite->checkThrows($name, ConfigurationException::class, $saveGeneral);
}

$saveInvalidWholeFile = function () : void {
    SettingsFile::saveWholeFile("sending:\n  sync_wait_seconds: 61\n", SettingsFile::getFileVersion());
};

$name = 'a whole file with an invalid section isn\'t saved';
$suite->checkThrows($name, InvalidInputException::class, $saveInvalidWholeFile);
SettingsFile::saveWholeFile("general:\n  timezone: \"Europe/Berlin\"\n", SettingsFile::getFileVersion());
$suite->check('a valid whole file is saved', Settings::get()->getTimezone()->getName() === 'Europe/Berlin');

// Exporting replaces secrets written out in full with references.
$useFile("google_sso:\n  enabled: false\n  client_id: \"id\"\n  client_secret: \"google-secret\"\n");
SettingsFile::saveProviders(SettingsFile::parseProvidersYaml($mailpitProviders), $emptyVersion);
$exported = SettingsFile::export();

$isSafe = (
       !str_contains($exported, 'google-secret')
    && !str_contains($exported, 'plaintext-secret')
    && str_contains($exported, '${GOOGLE_SSO_CLIENT_SECRET}')
    && str_contains($exported, '${PROVIDER_MAILPIT_PASSWORD}')
);

$suite->check('the export has no secrets written out in full', $isSafe, $exported);

// The mistakes each setting refuses, where the setting is made.
$noSecret = ConfigValue::createFromString('');
$someSecret = ConfigValue::createFromString('x');
$defaultPolicy = RetentionSettings::createDefault()->getEmailRetention();

$refused = [
    'a time zone that doesn\'t exist' => function () : void {
        GeneralSettings::createUsingTimezoneName('Mars/Olympus', DateTimeFormat::createDefault());
    },
    'every IPv4 address as a trusted proxy' => function () : void {
        new ProxySettings(false, new IpNetworkCollection(new IpNetwork('0.0.0.0/0')));
    },
    'every IPv6 address as a trusted proxy' => function () : void {
        new ProxySettings(false, new IpNetworkCollection(new IpNetwork('::/0')));
    },
    'a wait of more than 60 seconds' => function () : void {
        new SendingSettings('', 61);
    },
    'a negative wait' => function () : void {
        new SendingSettings('', -1);
    },
    'an invalid address for the service\'s own emails' => function () : void {
        new SendingSettings('not-an-address', 5);
    },
    'logs kept for 0 days' => function () use ($defaultPolicy) : void {
        new RetentionSettings($defaultPolicy, 0);
    },
    'Google switched on without a secret' => function () use ($noSecret) : void {
        new GoogleSsoConfig(true, 'id', $noSecret, SsoMfaBypass::NEVER);
    },
    'Google switched on without a client ID' => function () use ($someSecret) : void {
        new GoogleSsoConfig(true, '', $someSecret, SsoMfaBypass::NEVER);
    },
    'an unknown setting in a section' => function () : void {
        ServiceSettingsParser::parseSending(['sync_wait' => 5]);
    },
    'a setting of the wrong type' => function () : void {
        ServiceSettingsParser::parseSecurity(['require_mfa' => 'yes']);
    },
    'a proxy setting left in the security section' => function () : void {
        ServiceSettingsParser::parseSecurity(['trusted_proxies' => ['10.0.0.0/8']]);
    },
    'an unknown second factor choice' => function () : void {
        GoogleSsoConfigParser::parse(['bypass_mfa' => 'sometimes']);
    },
];

foreach ($refused as $what => $code)
{
    $suite->checkThrows("{$what} is refused", InvalidInputException::class, $code);
}

$useFile('', false);

$enableWithMissingVariable = function () use ($emptyVersion) : void {
    $secret = ConfigValue::createReference('NOT_A_SET_VARIABLE');
    SettingsFile::saveGoogleSso(new GoogleSsoConfig(true, 'id', $secret, SsoMfaBypass::NEVER), $emptyVersion);
};

$suite->checkThrows(
    'Google can\'t be switched on with its secret in a variable that isn\'t set',
    InvalidInputException::class,
    $enableWithMissingVariable
);

array_map('unlink', glob("{$folder}/*"));
rmdir($folder);
$suite->finish();


/**
 * Indent a line of YAML by two spaces, to go under a section's key.
 */
function SettingsFileTestIndent(string $line) : string
{
    return ($line === '') ? '' : '  ' . $line;
}
