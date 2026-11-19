<?php

/*
 * The resource variables (reading and writing their values), where PostgreSQL is taken to run, and the limits the
 * Settings page suggests for a server: both columns of suggestions, the warnings, and the rounding and clamping.
 */

declare(strict_types = 1);

require_once __DIR__ . '/TestSuite.php';

$suite = new TestSuite('Resource advisor');
$mib = ResourceVariable::MIB;
$gib = ResourceVariable::GIB;

// Reading and writing values.
$suite->check('a size in M is read', ResourceVariable::PHP_MEMORY_LIMIT->parseValue('40M') === 40 * $mib);
$suite->check('a size in lowercase g is read', ResourceVariable::PHP_MEMORY_LIMIT->parseValue('2g') === 2 * $gib);
$suite->check('a count is read', ResourceVariable::QUEUE_WORKERS->parseValue(' 6 ') === 6);
$suite->check('a PostgreSQL size is read', ResourceVariable::DB_WORK_MEM->parseValue('64MB') === 64 * $mib);

$badValues = [
    [ResourceVariable::HTTP_MAX_BODY_SIZE, '40'],
    [ResourceVariable::HTTP_MAX_BODY_SIZE, '40MB'],
    [ResourceVariable::HTTP_MAX_BODY_SIZE, '-1M'],
    [ResourceVariable::HTTP_MAX_BODY_SIZE, '0M'],
    [ResourceVariable::HTTP_MAX_BODY_SIZE, '1.5G'],
    [ResourceVariable::QUEUE_WORKERS, '0'],
    [ResourceVariable::QUEUE_WORKERS, 'four'],
    [ResourceVariable::SMTP_MAX_MESSAGE_BYTES, '20M'],
    [ResourceVariable::DB_WORK_MEM, '64M'],
];

foreach ($badValues as [$variable, $value])
{
    $parse = function () use ($variable, $value) : void {
        $variable->parseValue($value);
    };

    $suite->checkThrows("{$variable->value}=\"{$value}\" is refused", ConfigurationException::class, $parse);
}

$parseNamed = function () : void {
    ResourceVariable::PHP_MEMORY_LIMIT->parseValue('lots');
};

try
{
    $parseNamed();
    $suite->check('the error names the variable', false, 'nothing was thrown');
}
catch (ConfigurationException $e)
{
    $suite->check('the error names the variable', str_contains($e->getMessage(), 'PHP_MEMORY_LIMIT'), $e->getMessage());
}

$suite->check('a whole GiB is written in G', ResourceVariable::DB_MEMORY_LIMIT->formatValue(2 * $gib) === '2G');
$suite->check('other sizes are written in M', ResourceVariable::DB_MEMORY_LIMIT->formatValue(384 * $mib) === '384M');
$roundedUp = ResourceVariable::HTTP_MAX_BODY_SIZE->formatValue(27 * $mib + 1);
$suite->check('a part of a MiB is rounded up', $roundedUp === '28M', $roundedUp);
$suite->check('a PostgreSQL size in MB', ResourceVariable::DB_SHARED_BUFFERS->formatValue(64 * $mib) === '64MB');
$suite->check('a PostgreSQL size in GB', ResourceVariable::DB_SHARED_BUFFERS->formatValue(2 * $gib) === '2GB');
$suite->check('a PostgreSQL size in kB', ResourceVariable::DB_WORK_MEM->formatValue(1536 * 1024) === '1536kB');

foreach (ResourceVariable::cases() as $variable)
{
    $default = $variable->getDefault();
    $roundTrip = $variable->parseValue($variable->formatValue($default));
    $suite->check("{$variable->value}'s default is written as it's read", $roundTrip === $default);
}

// Where PostgreSQL is.
$gateway = '172.17.0.1';
$locate = function (string $host, string $dockerHostIp) : DatabaseLocation {
    return DatabaseLocation::createUsingHost($host, $dockerHostIp);
};

$suite->check('"db" is the bundled database', $locate('db', $gateway) === DatabaseLocation::BUNDLED);
$suite->check('localhost is this server', $locate('localhost', $gateway) === DatabaseLocation::SAME_SERVER);
$suite->check('127.0.0.1 is this server', $locate('127.0.0.1', $gateway) === DatabaseLocation::SAME_SERVER);
$suite->check('the Docker host is this server', $locate($gateway, $gateway) === DatabaseLocation::SAME_SERVER);
$suite->check('another address is another server', $locate('10.1.2.3', $gateway) === DatabaseLocation::EXTERNAL);
$suite->check('an unknown gateway matches nothing', $locate('10.1.2.3', '') === DatabaseLocation::EXTERNAL);

// The server.
$unlimited = new ServerResources($gib, 2 * $gib, 2, $gateway);
$isUnlimited = ($unlimited->hasContainerMemoryLimit() === false);
$suite->check('a container limit above the server\'s memory is no limit', $isUnlimited);

$createBadServer = function () : void {
    new ServerResources(0, 0, 2, '');
};

$suite->checkThrows('a server with no memory is refused', LogicException::class, $createBadServer);

// Suggestions.
$createAdvisor = function (
    int $memoryBytes,
    int $vcpus,
    DatabaseLocation $location,
    ResourceValues $values
) use ($gateway) : ResourceAdvisor {
    return new ResourceAdvisor(new ServerResources($memoryBytes, 0, $vcpus, $gateway), $location, $values);
};

$findSuggestion = function (ResourceAdvisor $advisor, ResourceVariable $variable) : ResourceSuggestion {
    $found = null;

    foreach ($advisor->getSuggestions() as $suggestion)
    {
        if ($suggestion->getVariable() === $variable)
        {
            $found = $suggestion;
        }
    }

    if ($found === null)
    {
        throw new LogicException("{$variable->value} wasn't suggested.");
    }

    return $found;
};

$hasSuggestion = function (ResourceAdvisor $advisor, ResourceVariable $variable) : bool {
    $isFound = false;

    foreach ($advisor->getSuggestions() as $suggestion)
    {
        $isFound = $isFound || ($suggestion->getVariable() === $variable);
    }

    return $isFound;
};

$hasWarning = function (ResourceAdvisor $advisor, string $text) : bool {
    $isFound = false;

    foreach ($advisor->getWarnings() as $warning)
    {
        $isFound = $isFound || str_contains($warning, $text);
    }

    return $isFound;
};

$defaults = ResourceValues::createDefaults();

// 1 GB and 2 vCPUs, with the bundled database: today's defaults, in both columns, but a tighter body size.
$small = $createAdvisor($gib, 2, DatabaseLocation::BUNDLED, $defaults);

foreach (ResourceVariable::cases() as $variable)
{
    $suggestion = $findSuggestion($small, $variable);

    $expected = ($variable === ResourceVariable::HTTP_MAX_BODY_SIZE)
        ? 28 * $mib
        : $variable->getDefault();

    $everything = $suggestion->getWithEverythingChanged();
    $onlyThis = $suggestion->getWithOnlyThisChanged();
    $isExpected = ($everything === $expected && $onlyThis === $expected);
    $detail = "{$everything} and {$onlyThis}, not {$expected}";
    $suite->check("1 GB, 2 vCPUs: {$variable->value} is suggested as the default", $isExpected, $detail);
}

$warnings = implode(' ', $small->getWarnings());
$suite->check('1 GB, 2 vCPUs: the defaults raise no warnings', $warnings === '', $warnings);
$suite->check('1 GB, 2 vCPUs: 512 MiB is left for the app', $small->getAppMemoryBytes() === 512 * $mib);
$suite->check('1 GB, 2 vCPUs: 3 emails at the size limit fit at once', $small->getLargeEmailsAtOnce() === 3);
$suite->check('the .env lines have each suggestion', str_contains($small->getEnvLines(), "QUEUE_WORKERS=4\n"));
$suite->check('and in .env\'s format', str_contains($small->getEnvLines(), 'DB_SHARED_BUFFERS=64MB'));

// Keeping more SMTP connections: "only this" works the others out with 100 kept, "everything" doesn't keep it.
$moreSmtpValues = $defaults->withValue(ResourceVariable::SMTP_MAX_CONNECTIONS, 100);
$moreSmtp = $createAdvisor($gib, 2, DatabaseLocation::BUNDLED, $moreSmtpValues);
$connections = $findSuggestion($moreSmtp, ResourceVariable::DB_MAX_CONNECTIONS);
$onlyThis = $connections->getWithOnlyThisChanged();
$suite->check('100 SMTP connections kept: max_connections fits them', $onlyThis === 240, (string)$onlyThis);
$suite->check('but with everything changed, it fits 30', $connections->getWithEverythingChanged() === 100);
$smtp = $findSuggestion($moreSmtp, ResourceVariable::SMTP_MAX_CONNECTIONS);
$isThirty = ($smtp->getWithEverythingChanged() === 30 && $smtp->getWithOnlyThisChanged() === 30);
$suite->check('and SMTP connections are suggested as 30', $isThirty);
$fpm = $findSuggestion($moreSmtp, ResourceVariable::PHP_FPM_MAX_CHILDREN);
$fpmOnlyThis = $fpm->getWithOnlyThisChanged();
$suite->check('the PHP-FPM processes are cut, with 100 SMTP connections kept', $fpmOnlyThis < 8, (string)$fpmOnlyThis);
$suite->check('but not with everything changed', $fpm->getWithEverythingChanged() === 8);
$isWarned = $hasWarning($moreSmtp, 'PostgreSQL accepts 100 connections');
$suite->check('too few database connections is warned about', $isWarned);
$suite->check('as is too little memory for every process', $hasWarning($moreSmtp, 'When every process is busy'));

// A larger size limit raises the memory and body limits, in both columns, and warns about the current ones.
$largeEmailValues = $defaults->withValue(ResourceVariable::SMTP_MAX_MESSAGE_BYTES, 50 * $mib);
$largeEmails = $createAdvisor($gib, 2, DatabaseLocation::BUNDLED, $largeEmailValues);
$php = $findSuggestion($largeEmails, ResourceVariable::PHP_MEMORY_LIMIT);
$isRaised = ($php->getWithEverythingChanged() === 278 * $mib && $php->getWithOnlyThisChanged() === 278 * $mib);
$suite->check('50 MiB emails: PHP_MEMORY_LIMIT is 278M', $isRaised);
$body = $findSuggestion($largeEmails, ResourceVariable::HTTP_MAX_BODY_SIZE);
$bodyBytes = $body->getWithEverythingChanged();
$suite->check('and HTTP_MAX_BODY_SIZE is 68M', $bodyBytes === 68 * $mib, (string)$bodyBytes);
$suite->check('the body size is warned about', $hasWarning($largeEmails, 'HTTP_MAX_BODY_SIZE is too small'));
$suite->check('as is PHP\'s memory', $hasWarning($largeEmails, 'PHP_MEMORY_LIMIT is too small'));
$suite->check('50 MiB emails fit the cron job\'s memory', $hasWarning($largeEmails, 'cron job') === false);
$fitsValues = $defaults->withValue(ResourceVariable::SMTP_MAX_MESSAGE_BYTES, 76 * $mib);
$fits = $createAdvisor($gib, 2, DatabaseLocation::BUNDLED, $fitsValues);
$suite->check('as do 76 MiB emails, the largest that do', $hasWarning($fits, 'cron job') === false);
$tooLargeValues = $defaults->withValue(ResourceVariable::SMTP_MAX_MESSAGE_BYTES, 77 * $mib);
$tooLarge = $createAdvisor($gib, 2, DatabaseLocation::BUNDLED, $tooLargeValues);
$suite->check('77 MiB emails are too large for the cron job\'s memory', $hasWarning($tooLarge, 'cron job'));
$isLargestNamed = $hasWarning($tooLarge, 'at most 76 MiB');
$suite->check('and the warning says the largest that fits', $isLargestNamed, implode(' ', $tooLarge->getWarnings()));
$message = $findSuggestion($largeEmails, ResourceVariable::SMTP_MAX_MESSAGE_BYTES);
$suite->check('the size limit is kept as it is', $message->getWithEverythingChanged() === 50 * $mib);

// A database on another server uses none of this server's memory, and only its connections are suggested.
$external = $createAdvisor($gib, 2, DatabaseLocation::EXTERNAL, $defaults);
$suite->check('external database: the app gets its memory', $external->getAppMemoryBytes() === 896 * $mib);
$isSuggested = $hasSuggestion($external, ResourceVariable::DB_MEMORY_LIMIT);
$suite->check('and PostgreSQL\'s memory isn\'t suggested', $isSuggested === false);
$suite->check('nor its shared buffers', $hasSuggestion($external, ResourceVariable::DB_SHARED_BUFFERS) === false);
$suite->check('but its connections are', $hasSuggestion($external, ResourceVariable::DB_MAX_CONNECTIONS));
$suite->check('more large emails fit at once', $external->getLargeEmailsAtOnce() > $small->getLargeEmailsAtOnce());
$sameServer = $createAdvisor($gib, 2, DatabaseLocation::SAME_SERVER, $defaults);
$suite->check('a database on the same server counts', $sameServer->getAppMemoryBytes() === 512 * $mib);

// A bigger server.
$big = $createAdvisor(8 * $gib, 4, DatabaseLocation::BUNDLED, $defaults);
$bigValues = $big->getEverythingChanged();
$suite->check('8 GB: PostgreSQL gets 3 GiB', $bigValues->get(ResourceVariable::DB_MEMORY_LIMIT) === 3 * $gib);
$suite->check('and 512 MiB of shared buffers', $bigValues->get(ResourceVariable::DB_SHARED_BUFFERS) === 512 * $mib);
$suite->check('4 vCPUs: 8 queue workers', $bigValues->get(ResourceVariable::QUEUE_WORKERS) === 8);
$suite->check('16 PHP-FPM processes', $bigValues->get(ResourceVariable::PHP_FPM_MAX_CHILDREN) === 16);
$suite->check('60 SMTP connections', $bigValues->get(ResourceVariable::SMTP_MAX_CONNECTIONS) === 60);
$suite->check('and 180 database connections', $bigValues->get(ResourceVariable::DB_MAX_CONNECTIONS) === 180);
$bigConnections = $findSuggestion($big, ResourceVariable::DB_MAX_CONNECTIONS);
$suite->check('but 100 if only that is changed', $bigConnections->getWithOnlyThisChanged() === 100);

// Clamping.
$huge = $createAdvisor(64 * $gib, 64, DatabaseLocation::BUNDLED, $defaults)->getEverythingChanged();
$suite->check('PostgreSQL\'s memory is capped at 8 GiB', $huge->get(ResourceVariable::DB_MEMORY_LIMIT) === 8 * $gib);
$suite->check('with 4 MiB of work_mem', $huge->get(ResourceVariable::DB_WORK_MEM) === 4 * $mib);
$suite->check('the queue workers are capped at 32', $huge->get(ResourceVariable::QUEUE_WORKERS) === 32);
$tiny = $createAdvisor(512 * $mib, 2, DatabaseLocation::BUNDLED, $defaults);
$tinyValues = $tiny->getEverythingChanged();
$tinyDatabase = $tinyValues->get(ResourceVariable::DB_MEMORY_LIMIT);
$suite->check('a tiny server: PostgreSQL gets at least 256 MiB', $tinyDatabase === 256 * $mib);

$isCut = (
       $tinyValues->get(ResourceVariable::SMTP_MAX_CONNECTIONS) < 30
    && $tinyValues->get(ResourceVariable::QUEUE_WORKERS) >= 1
);

$suite->check('every process count is cut, to at least 1', $isCut);
$suite->check('and the defaults are warned about', $hasWarning($tiny, 'When every process is busy'));

// PostgreSQL's own memory.
$greedyValues = $defaults->withValue(ResourceVariable::DB_WORK_MEM, 16 * $mib);
$greedy = $createAdvisor($gib, 2, DatabaseLocation::BUNDLED, $greedyValues);
$isGreedyWarned = $hasWarning($greedy, 'PostgreSQL could need');
$suite->check('work_mem too large for PostgreSQL\'s memory is warned about', $isGreedyWarned);
$externalGreedy = $createAdvisor($gib, 2, DatabaseLocation::EXTERNAL, $greedyValues);
$isExternalWarned = $hasWarning($externalGreedy, 'PostgreSQL could need');
$suite->check('but not for a database on another server', $isExternalWarned === false);

// A copy with a value of zero can't be made.
$withZero = function () use ($defaults) : void {
    $defaults->withValue(ResourceVariable::QUEUE_WORKERS, 0);
};

$suite->checkThrows('a value of zero is refused', LogicException::class, $withZero);

$suite->finish();
