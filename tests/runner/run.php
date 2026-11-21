<?php

/*
 * Runs every test suite, each against a fresh stack of its own, several at once. Each suite is put in a queue
 * (programster/pgsql-task-queue), with the longest first, and --workers=N workers (8 by default) take them from it,
 * each starting a stack for its suite, running it, and removing the stack, until they're all done. Then it prints each
 * suite's totals and failures, as the shell runner did.
 *
 * It's run by tests/run-tests.sh, inside the runner's container (tests/runner/Dockerfile), which starts the queue's
 * database and builds the app's image first.
 *
 * Usage: php run.php [--workers=N] [--keep] [--load]
 */

declare(strict_types = 1);

use Programster\PgsqlTaskQueue\Task;
use Programster\PgsqlTaskQueue\TaskCollection;
use Programster\PgsqlTaskQueue\TaskQueue;

require_once __DIR__ . '/bootstrap.php';


/**
 * Every suite, with roughly how long its tests take once its stack has started (measured, and mostly spent waiting on
 * real rate limit windows), so the longest are started first, and none is left to start at the end.
 */
function createSuites(bool $isLoadTesting) : SuiteCollection
{
    $contentKept = ['EMAIL_CONTENT_RETENTION_DAYS' => '30', 'EMAIL_ATTACHMENT_RETENTION_DAYS' => '-1'];
    $attachmentsKept = ['EMAIL_CONTENT_RETENTION_DAYS' => '30', 'EMAIL_ATTACHMENT_RETENTION_DAYS' => '7'];

    // Lower than the defaults, so the suite can tell they're applied, and too low for the default size limit, so the
    // Settings page warns about them.
    $lowLimits = [
        'HTTP_MAX_BODY_SIZE' => '2M',
        'PHP_MEMORY_LIMIT' => '96M',
        'DB_MEMORY_LIMIT' => '320M',
        'DB_MAX_CONNECTIONS' => '50',
    ];

    $unitTests = Suite::createUnitTests(
        'unit',
        2,
        'ValuesTest', 'SendGridTest', 'RetentionPolicyTest', 'AttachmentStripperTest', 'GoogleSsoTest', 'DatabaseTest',
        'OpenApiTest', 'QueueEstimatorTest', 'SenderChecksTest', 'CustomProviderTest', 'MailgunTest', 'PostmarkTest',
        'BrevoTest', 'MailjetTest', 'MailerSendTest', 'MailtrapTest', 'SettingsFileTest', 'SettingsMigrationTest',
        'ResourceAdvisorTest', 'NavigationTest', 'TokenTest',
        'DocumentationTest', 'ProviderFormTest', 'ClientIpTest', 'RateLimitReplyTest', 'PasswordPolicyTest',
        'BackupCodeTest', 'EmailContentTest', 'PasswordHashTest', 'ProviderRestrictionTest',
        'ProviderSendersTest', 'Smtp2GoTest'
    );

    $suites = new SuiteCollection(
        $unitTests,
        Suite::createPython('e2e', 'integration.e2e', 28),
        Suite::createPython('accounts', 'integration.accounts', 1),
        Suite::createPython('login-throttle', 'integration.login_throttle', 35),
        Suite::createPython('google-login', 'integration.google_login', 1),
        Suite::createPython('content-retention', 'integration.content_retention', 1),
        Suite::createPython('priority', 'integration.priority', 43),
        Suite::createPython('password-reset', 'integration.password_reset', 11),
        Suite::createPython('rate-limits', 'integration.rate_limits', 6),
        Suite::createPython('wait-estimate', 'integration.wait_estimate', 3),
        Suite::createPython('providers', 'integration.providers', 18),
        Suite::createPython('settings', 'integration.settings', 5),
        Suite::createPython('logging', 'integration.logging', 15),
        Suite::createPython('no-providers', 'integration.no_providers', 5, ['SETTINGS_FILE' => '/config/fresh.yaml']),
        Suite::createPython('attempts', 'integration.attempts', 2),
        Suite::createPython('content-retention-kept', 'integration.content_retention', 1, $contentKept),
        Suite::createPython('attachments-not-kept', 'integration.attachments', 7, $contentKept),
        Suite::createPython('attachments-kept', 'integration.attachments', 6, $attachmentsKept),
        Suite::createPython('resource-limits', 'integration.resource_limits', 2, $lowLimits),
        Suite::createPython('provider-form', 'integration.provider_form', 3),
        Suite::createPython('smtp-connections', 'integration.smtp_connections', 1, ['SMTP_MAX_CONNECTIONS' => '3'])
    );

    if ($isLoadTesting)
    {
        $suites = $suites->merge(new SuiteCollection(Suite::createPython('load', 'load.load_test', 120)));
    }

    return $suites;
}


/**
 * Put every suite in the queue, as one group, each with its stack's number (which sets its ports).
 * @return string - the group's name.
 */
function enqueueSuites(SuiteCollection $suites) : string
{
    $connection = QueueDatabase::connect();
    TaskQueue::createTables($connection);
    $group = 'run-' . bin2hex(random_bytes(8));
    $tasks = [];

    foreach ($suites as $index => $suite)
    {
        $tasks[] = Task::create(
            RunSuiteHandler::TYPE,
            ['suite' => $suite->toArray(), 'number' => $index + 1],
            queue: QueueDatabase::QUEUE_NAME,
            priority: $suite->getExpectedSeconds(),
            group: $group,
            leaseSeconds: 3600
        );
    }

    (new TaskQueue($connection))->enqueueAll(new TaskCollection(...$tasks));

    // The workers are started as processes of their own, so this one's connection isn't needed any more.
    pg_close($connection->getResource());
    return $group;
}


/**
 * Start the workers, and wait for them all to finish. Each prints a line as it finishes each suite.
 */
function runWorkers(int $numWorkers, string $group, bool $isKeepingStacks) : void
{
    $processes = [];
    $command = [PHP_BINARY, __DIR__ . '/worker.php', $group, $isKeepingStacks ? '1' : '0'];

    for ($i = 0; $i < $numWorkers; $i++)
    {
        $processes[] = proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => STDOUT, 2 => STDERR], $pipes);
    }

    foreach ($processes as $process)
    {
        if (is_resource($process))
        {
            proc_close($process);
        }
    }
}


/**
 * Print each suite's totals and failures from its output, as the shell runner did, and say whether they all passed.
 * @return bool - whether every suite passed.
 */
function reportResults(SuiteCollection $suites) : bool
{
    $isAllPassed = true;
    $failed = [];
    print PHP_EOL;

    foreach ($suites as $suite)
    {
        $dir = ROOT_DIR . "/tests/.run/{$suite->getName()}";

        $result = is_file("{$dir}/result.json")
            ? SuiteResult::fetchFromFile("{$dir}/result.json")
            : SuiteResult::createNotFinished($suite->getName());

        $output = is_file("{$dir}/output.log") ? (string)file_get_contents("{$dir}/output.log") : '';
        $pattern = '/^[A-Za-z].*: [0-9]+\/[0-9]+ passed$|^FAIL |Error|Traceback/';
        $lines = preg_grep($pattern, explode("\n", $output)) ?: [];

        print "## {$result->describe()}" . PHP_EOL;

        foreach ($lines as $line)
        {
            print "  {$line}" . PHP_EOL;
        }

        if (!$result->isPassed())
        {
            $isAllPassed = false;
            $failed[] = $suite->getName();
        }
    }

    print PHP_EOL;

    if ($isAllPassed)
    {
        print "Every test passed." . PHP_EOL;
    }
    else
    {
        print "Failed: " . implode(', ', $failed) . ". Each suite's full output is in tests/.run/<suite>/output.log."
            . PHP_EOL;
    }

    return $isAllPassed;
}


function main(string ...$arguments) : int
{
    $startedAt = time();
    $options = RunOptions::createFromArguments(...$arguments);
    $suites = createSuites($options->isLoadTesting());
    $numWorkers = min($options->getNumWorkers(), count($suites));
    $group = enqueueSuites($suites);

    print "Running " . count($suites) . " suites, each on a fresh stack, {$numWorkers} at once." . PHP_EOL;
    runWorkers($numWorkers, $group, $options->isKeepingStacks());
    $isAllPassed = reportResults($suites);
    print "Took " . (time() - $startedAt) . " seconds." . PHP_EOL;

    if ($options->isKeepingStacks())
    {
        print "The test stacks are still running (--keep). Remove them with: tests/run-tests.sh --remove" . PHP_EOL;
    }

    return $isAllPassed ? 0 : 1;
}


exit(main(...array_slice($argv, 1)));
