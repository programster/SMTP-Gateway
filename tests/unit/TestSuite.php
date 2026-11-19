<?php

/*
 * Records the checks a unit test file makes, printing each as PASS or FAIL as it goes, and exits with a failure
 * status at the end if any failed, so run-tests.sh can tell.
 *
 * The unit tests run inside the app container, with the site loaded, e.g.
 * docker exec -u www-data smtpapitest-app php /tmp/unit-tests/ValuesTest.php
 */

declare(strict_types = 1);

require_once '/var/www/site/bootstrap.php';


final class TestSuite
{
    private readonly string $m_name;
    private int $m_passed;
    private int $m_failed;


    /**
     * @param string $name - what the file tests, which is printed as its heading.
     */
    public function __construct(string $name)
    {
        $this->m_name = $name;
        $this->m_passed = 0;
        $this->m_failed = 0;
        echo "== {$name}" . PHP_EOL;
    }


    /**
     * Record a check.
     *
     * @param string $name - what's being checked, e.g. "domain rule matches".
     *
     * @param bool $passed - whether it passed.
     *
     * @param string $detail - what to show if it failed, to help work out why.
     *
     * @return void
     */
    public function check(string $name, bool $passed, string $detail = '') : void
    {
        if ($passed)
        {
            $this->m_passed++;
            echo "PASS {$name}" . PHP_EOL;
        }
        else
        {
            $this->m_failed++;
            echo "FAIL {$name} -- {$detail}" . PHP_EOL;
        }
    }


    /**
     * Check that some code throws a given exception (or one that extends it).
     *
     * @param string $name - what's being checked.
     *
     * @param string $exceptionClass - the exception it should throw, e.g. InvalidInputException::class.
     *
     * @param Closure $code - the code.
     *
     * @return void
     */
    public function checkThrows(string $name, string $exceptionClass, Closure $code) : void
    {
        try
        {
            $code();
            $isThrown = false;
            $detail = 'nothing was thrown';
        }
        catch (Throwable $e)
        {
            $isThrown = ($e instanceof $exceptionClass);
            $detail = $e::class . ': ' . $e->getMessage();
        }

        $this->check($name, $isThrown, $detail);
    }


    /**
     * Print the total, and exit: with status 1 if any check failed.
     */
    public function finish() : never
    {
        $total = $this->m_passed + $this->m_failed;
        echo "{$this->m_name}: {$this->m_passed}/{$total} passed" . PHP_EOL . PHP_EOL;
        exit(($this->m_failed > 0) ? 1 : 0);
    }
}
