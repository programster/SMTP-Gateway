<?php

/*
 * One of the test run's workers, started by run.php: takes the next suite from the queue, runs it against a fresh
 * stack of its own (see RunSuiteHandler), and carries on until every suite in the run has been handled.
 *
 * Usage: php worker.php <run's group> <1 to keep the stacks, or 0>
 */

declare(strict_types = 1);

use Programster\PgsqlTaskQueue\Exceptions\ExceptionTaskFailed;
use Programster\PgsqlTaskQueue\Exceptions\ExceptionTasksExpired;
use Programster\PgsqlTaskQueue\TaskQueue;
use Programster\PgsqlTaskQueue\Worker;

require_once __DIR__ . '/bootstrap.php';


function main(string $group, bool $isKeepingStacks) : void
{
    $queue = new TaskQueue(QueueDatabase::connect());
    $worker = new Worker($queue, [QueueDatabase::QUEUE_NAME], new RunSuiteHandler(ROOT_DIR, $isKeepingStacks));

    // The group still has the suites that other workers are running, so this waits for those too, then stops.
    while ($queue->fetchGroup($group)->count() > 0)
    {
        try
        {
            if (!$worker->runNext())
            {
                $worker->waitForTask(2);
            }
        }
        catch (ExceptionTaskFailed $failure)
        {
            // RunSuiteHandler records a failed suite rather than throwing, so this is a mistake in the runner.
            fwrite(STDERR, "A worker couldn't run a suite: {$failure->getPrevious()?->getMessage()}\n");
        }
        catch (ExceptionTasksExpired $expiry)
        {
            fwrite(STDERR, "{$expiry->getMessage()}\n");
        }
    }
}


main($argv[1] ?? '', ($argv[2] ?? '0') === '1');
