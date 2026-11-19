<?php

/*
 * Checks the environment configuration on start up, so a mistake is reported in the container's log straight away
 * (and the container stops), and says how long each part of an email is kept. A problem with settings.yaml is only
 * reported, rather than stopping the container, so it can be fixed on the Settings page.
 */

declare(strict_types = 1);

require_once(__DIR__ . '/../bootstrap.php');


function main() : void
{
    try
    {
        Config::get();
    }
    catch (ConfigurationException $e)
    {
        fwrite(STDERR, "ERROR: {$e->getMessage()}" . PHP_EOL);
        exit(1);
    }

    $settings = Settings::get();

    foreach ($settings->getProblems() as $problem)
    {
        fwrite(STDERR, "WARNING: {$problem}" . PHP_EOL);
    }

    try
    {
        $retention = $settings->getRetention();
        $emailRetention = $retention->getEmailRetention();
        print "Email records are kept for {$emailRetention->getRecordDays()} days." . PHP_EOL;
        print "An email's content is {$emailRetention->describeContent()}." . PHP_EOL;
        print "An email's attachments are {$emailRetention->describeAttachments()}." . PHP_EOL;
        print "The logs are kept for {$retention->getLogDays()} days." . PHP_EOL;
    }
    catch (ConfigurationException $e)
    {
        fwrite(STDERR, "WARNING: {$e->getMessage()}" . PHP_EOL);
    }
}


main();
