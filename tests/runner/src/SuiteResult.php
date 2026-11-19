<?php

/*
 * How a suite went: whether it passed, how long its stack took to start and its tests took to run, and why it failed,
 * if it couldn't run at all. Each worker writes one to tests/.run/<suite>/result.json, for run.php to read at the end.
 */

declare(strict_types = 1);


final readonly class SuiteResult
{
    private string $m_name;
    private bool $m_passed;
    private int $m_stackSeconds;
    private int $m_testSeconds;
    private string $m_problem;


    /**
     * @param string $name - the suite's name.
     * @param bool $passed - whether every test in it passed.
     * @param int $stackSeconds - how long its stack took to start.
     * @param int $testSeconds - how long its tests took.
     * @param string $problem - why it couldn't run, e.g. its stack didn't start, or an empty string if it ran.
     */
    public function __construct(string $name, bool $passed, int $stackSeconds, int $testSeconds, string $problem)
    {
        $this->m_name = $name;
        $this->m_passed = $passed;
        $this->m_stackSeconds = $stackSeconds;
        $this->m_testSeconds = $testSeconds;
        $this->m_problem = $problem;
    }


    /**
     * Create a result for a suite that was never run, or whose worker stopped partway through it.
     * @param string $name - the suite's name.
     * @return SuiteResult
     */
    public static function createNotFinished(string $name) : SuiteResult
    {
        return new SuiteResult($name, false, 0, 0, "It didn't finish, as its worker stopped partway through it.");
    }


    /**
     * Read the result a worker wrote, with saveToFile().
     * @param string $path - the file.
     * @return SuiteResult
     * @throws RuntimeException - if it can't be read, or isn't a result.
     */
    public static function fetchFromFile(string $path) : SuiteResult
    {
        $json = @file_get_contents($path);
        $data = is_string($json) ? json_decode($json, true) : null;

        if (
               !is_array($data)
            || !is_string($data['name'] ?? null)
            || !is_bool($data['passed'] ?? null)
            || !is_int($data['stack_seconds'] ?? null)
            || !is_int($data['test_seconds'] ?? null)
            || !is_string($data['problem'] ?? null)
        )
        {
            throw new RuntimeException("{$path} isn't a suite's result.");
        }

        return new SuiteResult(
            $data['name'],
            $data['passed'],
            $data['stack_seconds'],
            $data['test_seconds'],
            $data['problem']
        );
    }


    /**
     * Write the result to a file, for fetchFromFile() to read.
     * @param string $path - the file.
     * @return void
     * @throws RuntimeException - if it can't be written.
     */
    public function saveToFile(string $path) : void
    {
        $data = [
            'name' => $this->m_name,
            'passed' => $this->m_passed,
            'stack_seconds' => $this->m_stackSeconds,
            'test_seconds' => $this->m_testSeconds,
            'problem' => $this->m_problem,
        ];

        if (file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)) === false)
        {
            throw new RuntimeException("Couldn't write {$path}.");
        }
    }


    /**
     * Describe the result in a line, e.g. "e2e: passed (stack 21s, tests 64s)".
     * @return string
     */
    public function describe() : string
    {
        $outcome = $this->m_passed ? "passed" : "FAILED";
        $timing = "stack {$this->m_stackSeconds}s, tests {$this->m_testSeconds}s";
        $problem = ($this->m_problem === '') ? '' : ": {$this->m_problem}";
        return "{$this->m_name}: {$outcome} ({$timing}){$problem}";
    }


    # Accessors
    public function getName() : string { return $this->m_name; }
    public function isPassed() : bool { return $this->m_passed; }
    public function getStackSeconds() : int { return $this->m_stackSeconds; }
    public function getTestSeconds() : int { return $this->m_testSeconds; }
    public function getProblem() : string { return $this->m_problem; }
}
