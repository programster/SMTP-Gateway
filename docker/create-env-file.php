<?php

/*
 * This script creates a .env file from expected variables specified in this file.
 * If we find that an expected environment variable is missing, then we notify you and exit with a -1 exit code.
 * Call it with php create-env-file.php [output filepath]
 */

define('REQUIRED_ENV_VARS', [
    "ENVIRONMENT",

    "DB_USER",
    "DB_PASSWORD",
    "DB_HOST",
    "DB_NAME",

    "ADMIN_EMAIL",
    "ADMIN_PASSWORD",
]);

define('OPTIONAL_ENV_VARS', [
    "DB_PORT",
    "SETTINGS_FILE",
    "QUEUE_WORKERS",
    "PHP_FPM_MAX_CHILDREN",
    "SMTP_MAX_CONNECTIONS",
    "SMTP_SERVER_HOSTNAME",
    "SMTP_TLS_CERT_FILE",
    "SMTP_TLS_KEY_FILE",
    "SMTP_MAX_MESSAGE_BYTES",
    "HTTP_MAX_BODY_SIZE",
    "PHP_MEMORY_LIMIT",
    "DB_MEMORY_LIMIT",
    "PUBLIC_URL",
]);

// The settings an earlier version read from the environment, which are now in settings.yaml (set on the Settings
// page). They're only passed through so that scripts/migrate-settings.php can carry an existing install's settings
// over, on the first start after upgrading.
define('MIGRATED_ENV_VARS', [
    "PROVIDERS_CONFIG_FILE",
    "TIMEZONE",
    "REQUIRE_MFA",
    "TRUSTED_PROXIES",
    "SYSTEM_EMAIL_FROM",
    "SYNC_WAIT_SECONDS",
    "EMAIL_RECORD_RETENTION_DAYS",
    "EMAIL_CONTENT_RETENTION_DAYS",
    "EMAIL_ATTACHMENT_RETENTION_DAYS",
    "LOG_RETENTION_DAYS",
    "GOOGLE_SSO_ENABLED",
    "GOOGLE_SSO_CLIENT_ID",
    "GOOGLE_SSO_CLIENT_SECRET",
    "GOOGLE_SSO_BYPASS_MFA",
]);


/**
 * Write the expected environment variables out to a .env file that the web user can read.
 * Values are written in single quotes, so the dotenv parser takes them literally (e.g. a base64 public key ending
 * in "=", or a password with a "$" in it).
 *
 * @param string $outputFilepath - where to write the .env file.
 *
 * @return void
 */
/**
 * Get the names of the environment variables that settings.yaml refers to (as "${NAME}"), so they're passed through
 * too, whatever they're called. An earlier version's providers.yaml is read too, as it's carried over to settings.yaml
 * after this runs, on the first start after upgrading.
 *
 * @return string[]
 */
function getSettingsReferences() : array
{
    $settingsPath = getenv('SETTINGS_FILE') ?: '/config/settings.yaml';
    $oldProvidersPath = getenv('PROVIDERS_CONFIG_FILE') ?: dirname($settingsPath) . '/providers.yaml';
    $names = [];

    foreach ([$settingsPath, $oldProvidersPath] as $path)
    {
        $contents = is_readable($path) ? (string)file_get_contents($path) : '';
        preg_match_all('/\$\{([A-Z_][A-Z0-9_]*)\}/', $contents, $matches);
        $names = [...$names, ...$matches[1]];
    }

    return array_values(array_unique($names));
}


function main(string $outputFilepath) : void
{
    $lines = [];
    $missing = [];
    $badValues = [];

    $names = [...REQUIRED_ENV_VARS, ...OPTIONAL_ENV_VARS, ...MIGRATED_ENV_VARS, ...getSettingsReferences()];

    foreach (array_unique($names) as $name)
    {
        $value = getenv($name);

        if ($value === false || $value === '')
        {
            if (in_array($name, REQUIRED_ENV_VARS, true))
            {
                $missing[] = $name;
            }
        }
        elseif (str_contains($value, "'") || str_contains($value, "\n"))
        {
            $badValues[] = $name;
        }
        else
        {
            $lines[] = "{$name}='{$value}'";
        }
    }

    if (count($missing) > 0 || count($badValues) > 0)
    {
        foreach ($missing as $name)
        {
            fwrite(STDERR, "Missing required environment variable: {$name}" . PHP_EOL);
        }

        foreach ($badValues as $name)
        {
            fwrite(STDERR, "Environment variable {$name} can't contain a single quote or a newline." . PHP_EOL);
        }

        exit(-1);
    }

    file_put_contents($outputFilepath, implode(PHP_EOL, $lines) . PHP_EOL);
}


if (count($argv) < 2)
{
    fwrite(STDERR, "Missing expected output filepath as parameter." . PHP_EOL);
    exit(-1);
}

main($argv[1]);
