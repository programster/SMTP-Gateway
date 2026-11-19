<?php

/*
 * Run by startup.sh, before supervisord starts anything, to apply the .env variables that limit the memory and CPU
 * the app uses (or their defaults, which suit a VPS with 1 GB of memory and 2 vCPUs). They're read through Config, so
 * they're checked, and their defaults kept, in one place. It writes:
 *  - an nginx include, setting the largest request body (HTTP_MAX_BODY_SIZE), and
 *  - a shell file for startup.sh to source, exporting the values that supervisord.conf and php-fpm-pool.conf use.
 *
 * Usage: php write-runtime-config.php <nginx include path> <shell file path>
 */

declare(strict_types = 1);

require_once(__DIR__ . '/../bootstrap.php');


function main(string $nginxPath, string $shellPath) : void
{
    $config = Config::get();

    $exports = [
        ResourceVariable::QUEUE_WORKERS->value => $config->getQueueWorkers(),
        ResourceVariable::PHP_FPM_MAX_CHILDREN->value => $config->getPhpFpmMaxChildren(),
        ResourceVariable::HTTP_MAX_BODY_SIZE->value => $config->getHttpMaxBodyBytes(),
        ResourceVariable::PHP_MEMORY_LIMIT->value => $config->getPhpMemoryLimitBytes(),
    ];

    $lines = [];
    $summaries = [];

    foreach ($exports as $name => $value)
    {
        $formatted = ResourceVariable::from($name)->formatValue($value);
        $lines[] = "export {$name}='{$formatted}'";
        $summaries[] = "{$name}={$formatted}";
    }

    $bodySize = ResourceVariable::HTTP_MAX_BODY_SIZE->formatValue($config->getHttpMaxBodyBytes());
    $nginx = "# Written by write-runtime-config.php from HTTP_MAX_BODY_SIZE.\nclient_max_body_size {$bodySize};\n";
    $shell = "# Written by write-runtime-config.php.\n" . implode("\n", $lines) . "\n";

    if (file_put_contents($nginxPath, $nginx) === false || file_put_contents($shellPath, $shell) === false)
    {
        $message = "ERROR: the runtime configuration couldn't be written to {$nginxPath} and {$shellPath}.";
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }

    print 'The limits applied: ' . implode(', ', $summaries) . '.' . PHP_EOL;
}


if (count($argv) !== 3)
{
    fwrite(STDERR, 'Usage: php write-runtime-config.php <nginx include path> <shell file path>' . PHP_EOL);
    exit(2);
}

main($argv[1], $argv[2]);
