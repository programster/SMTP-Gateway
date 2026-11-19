<?php

/*
 * This is a really simple/basic script that has to do just one job. Keep trying to get a database connection until
 * one becomes available. This allows us to block startup of the site until the database is up and running.
 *
 * It asks ServiceFactory for the connection, like everything else, rather than opening one itself. A failed attempt
 * isn't cached, so each try is a fresh attempt to connect.
 */

declare(strict_types = 1);

require_once(__DIR__ . '/../bootstrap.php');


function main() : void
{
    $connected = false;

    while ($connected === false)
    {
        try
        {
            ServiceFactory::getDb();
            $connected = true;
        }
        catch (\Programster\PgsqlLib\Exceptions\ExceptionConnectionError)
        {
            print "Database still warming up. Waiting for it to come online..." . PHP_EOL;

            // wait for a bit to give the database time to spin up. No point wasting CPU.
            sleep(1);
        }
    }
}


main();
