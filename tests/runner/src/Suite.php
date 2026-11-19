<?php

/*
 * One task for the test runner: what to run, against a fresh stack of its own, with any settings that stack needs
 * changed from tests/test.env. Each is queued as a task (see RunSuiteHandler), so it's converted to and from the task's
 * data.
 */

declare(strict_types = 1);


final readonly class Suite
{
    private string $m_name;
    private SuiteKind $m_kind;

    /** @var list<string> */
    private array $m_tests;

    /** @var array<string, string> */
    private array $m_settings;

    private int $m_expectedSeconds;


    /**
     * @param string $name - a name for it that's unique in the run, of lowercase letters, digits and hyphens, as it
     * names its stack (and its folder in tests/.run).
     *
     * @param SuiteKind $kind - how it's run.
     *
     * @param list<string> $tests - the unit test scripts (without ".php"), in order, or the one Python module.
     *
     * @param array<string, string> $settings - the settings its stack needs changed from tests/test.env, by name.
     *
     * @param int $expectedSeconds - roughly how long it takes, so the longest are started first.
     *
     * @throws InvalidArgumentException - if the name isn't valid, or a Python suite isn't given exactly one module.
     */
    private function __construct(SuiteKind $kind, string $name, array $tests, array $settings, int $expectedSeconds)
    {
        if (preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/', $name) !== 1)
        {
            $rule = "use lowercase letters, digits and hyphens";
            throw new InvalidArgumentException("\"{$name}\" can't name a suite: {$rule}.");
        }

        if ($kind === SuiteKind::PYTHON && count($tests) !== 1)
        {
            throw new InvalidArgumentException("The Python suite \"{$name}\" must be given exactly one module.");
        }

        $this->m_name = $name;
        $this->m_kind = $kind;
        $this->m_tests = $tests;
        $this->m_settings = $settings;
        $this->m_expectedSeconds = $expectedSeconds;
    }


    /**
     * Create the suite of PHP unit tests, run one after another inside one stack's app container.
     * @param string $name - its name (see the constructor).
     * @param int $expectedSeconds - roughly how long they take.
     * @param string ...$scripts - the scripts in tests/unit, without ".php", in order.
     * @return Suite
     */
    public static function createUnitTests(string $name, int $expectedSeconds, string ...$scripts) : Suite
    {
        return new Suite(SuiteKind::UNIT, $name, array_values($scripts), [], $expectedSeconds);
    }


    /**
     * Create a Python suite.
     *
     * @param string $name - its name (see the constructor).
     *
     * @param string $module - the module to run from the tests folder, e.g. "integration.e2e".
     *
     * @param int $expectedSeconds - roughly how long it takes.
     *
     * @param array<string, string> $settings - the settings its stack needs changed from tests/test.env, by name.
     * Defaults to none.
     *
     * @return Suite
     */
    public static function createPython(
        string $name,
        string $module,
        int $expectedSeconds,
        array $settings = []
    ) : Suite
    {
        return new Suite(SuiteKind::PYTHON, $name, [$module], $settings, $expectedSeconds);
    }


    /**
     * Create a suite from a task's data, as toArray() made it.
     * @param array<mixed> $data - the task's data.
     * @return Suite
     * @throws InvalidArgumentException - if it isn't a suite's data.
     */
    public static function createFromArray(array $data) : Suite
    {
        $kind = SuiteKind::tryFrom(is_string($data['kind'] ?? null) ? $data['kind'] : '');
        $tests = $data['tests'] ?? null;
        $settings = $data['settings'] ?? null;

        if (
               $kind === null
            || !is_string($data['name'] ?? null)
            || !is_array($tests)
            || !array_is_list($tests)
            || !is_array($settings)
            || !is_int($data['expected_seconds'] ?? null)
        )
        {
            throw new InvalidArgumentException("A task's data isn't a suite: " . json_encode($data));
        }

        /** @var list<string> $tests */
        /** @var array<string, string> $settings */
        return new Suite($kind, $data['name'], $tests, $settings, $data['expected_seconds']);
    }


    /**
     * Get the suite as a task's data.
     * @return array<string, mixed>
     */
    public function toArray() : array
    {
        return [
            'name' => $this->m_name,
            'kind' => $this->m_kind->value,
            'tests' => $this->m_tests,
            'settings' => (object)$this->m_settings,
            'expected_seconds' => $this->m_expectedSeconds,
        ];
    }


    # Accessors
    public function getName() : string { return $this->m_name; }
    public function getKind() : SuiteKind { return $this->m_kind; }
    /** @return list<string> */
    public function getTests() : array { return $this->m_tests; }
    /** @return array<string, string> */
    public function getSettings() : array { return $this->m_settings; }
    public function getExpectedSeconds() : int { return $this->m_expectedSeconds; }
}
