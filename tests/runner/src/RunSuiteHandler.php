<?php

/*
 * Runs one suite, for a worker: starts a fresh stack for it, runs its tests, records how it went in
 * tests/.run/<suite>/result.json and prints it, and removes the stack (unless it's being kept).
 *
 * A suite that fails, or whose stack doesn't start, is recorded as failed, rather than thrown, so the worker carries on
 * with the next suite. Only a mistake in the runner itself is thrown.
 */

declare(strict_types = 1);

use Programster\PgsqlTaskQueue\Task;
use Programster\PgsqlTaskQueue\TaskHandlerInterface;


final readonly class RunSuiteHandler implements TaskHandlerInterface
{
    public const TYPE = 'run-suite';

    private string $m_rootDir;
    private bool $m_isKeepingStacks;


    /**
     * @param string $rootDir - the repository's root.
     * @param bool $isKeepingStacks - whether to leave each stack running once its suite has run (--keep).
     */
    public function __construct(string $rootDir, bool $isKeepingStacks)
    {
        $this->m_rootDir = $rootDir;
        $this->m_isKeepingStacks = $isKeepingStacks;
    }


    public function getType() : string
    {
        return self::TYPE;
    }


    /**
     * Run the suite in the task.
     * @param Task $task - the task, whose data is a suite's (see Suite::toArray()), and its stack's number.
     * @return void
     * @throws InvalidArgumentException - if the task's data isn't a suite's.
     */
    public function handle(Task $task) : void
    {
        $data = $task->getData();
        $suite = Suite::createFromArray(is_array($data['suite'] ?? null) ? $data['suite'] : []);
        $number = is_int($data['number'] ?? null) ? $data['number'] : 0;
        $stack = new TestStack($this->m_rootDir, $suite->getName(), $number, $suite->getSettings());
        $startedAt = time();
        $stackSeconds = 0;
        $passed = false;
        $problem = '';

        try
        {
            $stack->start();
            $stackSeconds = time() - $startedAt;

            $passed = match ($suite->getKind()) {
                SuiteKind::UNIT => $stack->runUnitTests(...$suite->getTests()),
                SuiteKind::PYTHON => $stack->runPythonSuite($suite->getTests()[0]),
            };
        }
        catch (RuntimeException $e)
        {
            $problem = $e->getMessage();
        }

        $testSeconds = time() - $startedAt - $stackSeconds;
        $result = new SuiteResult($suite->getName(), $passed, $stackSeconds, $testSeconds, $problem);
        $result->saveToFile("{$stack->getDir()}/result.json");
        fwrite(STDOUT, "  {$result->describe()}\n");

        if (!$this->m_isKeepingStacks)
        {
            $stack->remove();
        }
    }
}
