<?php

/*
 * Runs the SMTP server (ports 465 and 587). Started by supervisor, as root, so that it can open those ports; it
 * drops to www-data straight after.
 */

declare(strict_types = 1);

require_once(__DIR__ . '/../bootstrap.php');

try
{
    (new SmtpServer())->run();
}
catch (Throwable $e)
{
    fwrite(STDERR, "The SMTP server stopped: {$e->getMessage()}" . PHP_EOL);
    ServiceFactory::getLogger(LogCategory::EMAIL)->critical('The SMTP server stopped', ['error' => $e->getMessage()]);
    exit(1);
}
