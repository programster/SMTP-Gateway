<?php

/*
 * scripts/migrate-settings.php, which carries an earlier version's settings over to settings.yaml on the first start
 * after upgrading: providers.yaml (comments and all), and the settings that were environment variables. Each run is a
 * separate process, given the old settings in its environment, and a settings.yaml path in a temporary folder.
 */

declare(strict_types = 1);

require_once __DIR__ . '/TestSuite.php';

$suite = new TestSuite('Settings migration');
$folder = sys_get_temp_dir() . '/migration-test-' . bin2hex(random_bytes(4));
mkdir($folder);
$settingsPath = "{$folder}/settings.yaml";
$providersPath = "{$folder}/providers.yaml";

/**
 * Run the migration with the environment variables given (and SETTINGS_FILE), and get what it printed.
 */
$migrate = function (array $environment) use ($settingsPath) : string {
    $assignments = '';

    foreach (['SETTINGS_FILE' => $settingsPath, ...$environment] as $name => $value)
    {
        $assignments .= escapeshellarg("{$name}={$value}") . ' ';
    }

    $command = "env {$assignments} php /var/www/site/scripts/migrate-settings.php 2>&1";
    return (string)shell_exec($command);
};

/**
 * Read the migrated settings.yaml, as Settings and SettingsFile see it.
 */
$useMigratedFile = function () use ($settingsPath) : ServiceSettings {
    putenv("SETTINGS_FILE={$settingsPath}");
    return Settings::get();
};

// A fresh install: nothing to carry over, so no file is created.
$output = $migrate([]);
$suite->check('a fresh install creates no file', !file_exists($settingsPath), $output);

// An earlier version's providers.yaml, and every setting that was an environment variable.
$oldProviders = <<<'YAML'
    # An earlier version's providers, with a comment that's kept.
    mode: "fallback"
    providers:
      - name: "mailpit"
        type: "custom"
        enabled: true
        host: "mailpit"
        port: 1025
        security: "none"
        username: ""
        password: ""
        rate_limits: []
    YAML;

file_put_contents($providersPath, $oldProviders);

$output = $migrate([
    'PROVIDERS_CONFIG_FILE' => $providersPath,
    'TIMEZONE' => 'Europe/Madrid',
    'REQUIRE_MFA' => 'false',
    'TRUSTED_PROXIES' => '10.0.0.0/8, 172.16.0.0/12',
    'SYSTEM_EMAIL_FROM' => 'noreply@example.org',
    'SYNC_WAIT_SECONDS' => '90',
    'EMAIL_RECORD_RETENTION_DAYS' => '60',
    'EMAIL_CONTENT_RETENTION_DAYS' => '30',
    'EMAIL_ATTACHMENT_RETENTION_DAYS' => '7',
    'LOG_RETENTION_DAYS' => '14',
    'GOOGLE_SSO_ENABLED' => 'true',
    'GOOGLE_SSO_CLIENT_ID' => 'old-client.apps.googleusercontent.com',
    'GOOGLE_SSO_CLIENT_SECRET' => 'old-secret',
    'GOOGLE_SSO_BYPASS_MFA' => 'always',
]);

$suite->check('an earlier version\'s settings create settings.yaml', file_exists($settingsPath), $output);
$settings = $useMigratedFile();
$retention = $settings->getRetention();
$policy = $retention->getEmailRetention();
$googleSso = $settings->getGoogleSso();

$proxies = [];

foreach ($settings->getTrustedProxies() as $proxy)
{
    $proxies[] = (string)$proxy;
}

$isCarried = (
       $settings->getTimezone()->getName() === 'Europe/Madrid'
    && $settings->isMfaRequired() === false
    && $proxies === ['10.0.0.0/8', '172.16.0.0/12']
    && (string)$settings->getSending()->getSystemEmailFrom() === 'noreply@example.org'
    && $policy->getRecordDays() === 60
    && $policy->getContentSetting() === 30
    && $policy->getAttachmentSetting() === 7
    && $retention->getLogDays() === 14
    && $googleSso->isEnabled()
    && $googleSso->getClientId() === 'old-client.apps.googleusercontent.com'
    && $googleSso->getClientSecret()->toConfigString() === 'old-secret'
    && $googleSso->getMfaBypass() === SsoMfaBypass::ALWAYS
    && count($settings->getProblems()) === 0
);

$suite->check('every setting is carried over', $isCarried, (string)file_get_contents($settingsPath));
$suite->check('a wait of more than 60 seconds is cut to 60', $settings->getSyncWaitSeconds() === 60);
$providers = SettingsFile::loadProviders();
$suite->check('the providers are carried over', $providers->getMode() === ProviderMode::FALLBACK);
$isCommentKept = str_contains((string)file_get_contents($settingsPath), '# An earlier version\'s providers');
$suite->check('as they were written, comments and all', $isCommentKept);
$isRenamed = (!file_exists($providersPath) && file_exists("{$providersPath}.migrated"));
$suite->check('providers.yaml is renamed, as it\'s no longer used', $isRenamed);

// Once settings.yaml exists, it's never changed.
$before = (string)file_get_contents($settingsPath);
$output = $migrate(['TIMEZONE' => 'Asia/Tokyo']);
$suite->check('it does nothing once settings.yaml exists', file_get_contents($settingsPath) === $before, $output);

// Only the sections with something set are written, so everything else keeps its default.
unlink($settingsPath);
$output = $migrate(['TIMEZONE' => 'Asia/Tokyo']);
$sections = SettingsFile::decodeYaml((string)file_get_contents($settingsPath));
$isOnlyGeneral = is_array($sections) && array_keys($sections) === ['general'];
$suite->check('only the sections with something set are written', $isOnlyGeneral, $output);

// Something that can't be carried over is reported, and nothing is changed, to be tried again on the next start.
unlink($settingsPath);
$output = $migrate(['EMAIL_RECORD_RETENTION_DAYS' => '30', 'EMAIL_CONTENT_RETENTION_DAYS' => '31']);
$isLeftAlone = (!file_exists($settingsPath) && str_contains($output, 'couldn\'t be carried over'));
$suite->check('invalid settings are reported, and nothing is written', $isLeftAlone, $output);

array_map('unlink', glob("{$folder}/*"));
rmdir($folder);
$suite->finish();
