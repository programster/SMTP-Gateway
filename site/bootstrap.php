<?php

declare(strict_types = 1);

require_once(__DIR__ . '/defines.php');
require_once(__DIR__ . '/vendor/autoload.php');


$dotenv = new Symfony\Component\Dotenv\Dotenv();
$dotenv->usePutenv(true);
$dotenv->overload('/.env');

$requiredEnvVars = [
    "ENVIRONMENT",
    "DB_USER",
    "DB_PASSWORD",
    "DB_NAME",
    "DB_HOST",
];

foreach ($requiredEnvVars as $requiredEnvVar)
{
    if (getenv($requiredEnvVar) === false)
    {
        throw new Exception("Required environment variable not set: " . $requiredEnvVar);
    }
}

define('ENVIRONMENT', getenv('ENVIRONMENT'));
define('DB_HOST', getenv('DB_HOST'));
define('DB_USER', getenv('DB_USER'));
define('DB_PASSWORD', getenv('DB_PASSWORD'));
define('DB_NAME', getenv('DB_NAME'));

// The folders that classes are autoloaded from. The autoloader doesn't look in subfolders, so every folder of
// classes is listed, including the subfolders of these.
$classFolders = [
    __DIR__ . "/controllers",
    __DIR__ . "/exceptions",
    __DIR__ . "/libs",
    __DIR__ . "/middleware",
    __DIR__ . "/models",
    __DIR__ . "/providers",
    __DIR__ . "/services",
    __DIR__ . "/smtp",
    __DIR__ . "/views",
];

$autoloadFolders = [];

foreach ($classFolders as $classFolder)
{
    $autoloadFolders[] = $classFolder;

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($classFolder, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($iterator as $item)
    {
        if ($item->isDir())
        {
            $autoloadFolders[] = $item->getPathname();
        }
    }
}

$autoloader = new \Programster\Autoloader\Autoloader($autoloadFolders);

// Read the configuration now, so a mistake in it stops every script (and the container, at start up) with a clear
// message, rather than a stack trace, or a failure part way through something.
try
{
    $config = Config::get();
}
catch (ConfigurationException $e)
{
    $message = "ERROR: the configuration is invalid. {$e->getMessage()}" . PHP_EOL;

    if (PHP_SAPI === 'cli')
    {
        fwrite(STDERR, $message);
    }
    else
    {
        error_log($message);
        http_response_code(500);
    }

    exit(1);
}

// Times are shown in the time zone set on the Settings page.
date_default_timezone_set(Settings::get()->getTimezone()->getName());
