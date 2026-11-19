<?php

/*
 * Run by startup.sh, once the database is up, to say in the container's log if the limits on the memory and CPU the
 * service uses don't fit together, or don't fit the server (the same warnings as the Server resources page shows).
 * It never stops the container, as the service can still run, and that page suggests what to set.
 */

declare(strict_types = 1);

require_once(__DIR__ . '/../bootstrap.php');


function main() : void
{
    try
    {
        $advisor = ResourceAdvisor::createUsingRunningService();
        $server = $advisor->getServer();
        $memory = ResourceVariable::describeBytes($server->getTotalMemoryBytes());
        print "The server has {$memory} of memory and {$server->getVcpus()} vCPUs." . PHP_EOL;

        foreach ($advisor->getWarnings() as $warning)
        {
            fwrite(STDERR, "WARNING: {$warning} See Settings > Server resources for suggested values." . PHP_EOL);
        }
    }
    catch (Throwable $e)
    {
        fwrite(STDERR, "WARNING: the server's resources couldn't be checked: {$e->getMessage()}" . PHP_EOL);
    }
}


main();
