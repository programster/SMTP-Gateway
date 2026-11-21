<?php

/*
 * One suite's test stack: the development stack (docker-dev-compose.yml, with tests/docker-compose.tests.yml over it)
 * under its own project name and ports, with the settings in tests/test.env and any the suite changes, and its own
 * settings.yaml (from tests/settings.yaml, with the suite's retention settings), logs and state, in
 * tests/.run/<suite>. Each suite gets a fresh one, so nothing another suite did can affect it.
 */

declare(strict_types = 1);


final readonly class TestStack
{
    # The web interface's port for stack 0. Each stack's ports are 100 above the last's, as in the stacks before.
    private const BASE_WEB_PORT = 18080;

    # Each stack's network is <this>.<its number>.0/24, rather than one from Docker's default pools, which only have
    # room for around 30 networks across every project on the machine, so a large run could use them up.
    private const SUBNET_PREFIX = '10.213';

    private string $m_rootDir;
    private string $m_name;

    /** @var array<string, string> */
    private array $m_environment;


    /**
     * @param string $rootDir - the repository's root.
     *
     * @param string $name - the stack's name: its suite's name.
     *
     * @param int $number - the stack's number in the run, from 1, which sets its ports. Each stack in a run needs its
     * own, so they can all run at once.
     *
     * @param array<string, string> $settings - the settings to change from tests/test.env, by name.
     *
     * @throws InvalidArgumentException - if the number can't be given a subnet of its own.
     * @throws RuntimeException - if tests/test.env can't be read.
     */
    public function __construct(string $rootDir, string $name, int $number, array $settings)
    {
        if ($number < 1 || $number > 254)
        {
            throw new InvalidArgumentException("A test stack's number must be from 1 to 254, not {$number}.");
        }

        $offset = $number * 100;
        $webPort = self::BASE_WEB_PORT + $offset;

        $added = [
            'TEST_STACK' => $name,
            'COMPOSE_PROJECT_NAME' => "smtpapitest-{$name}",
            'TEST_SUBNET' => self::SUBNET_PREFIX . ".{$number}.0/24",
            'WEB_HOST_PORT' => (string)$webPort,
            'PUBLIC_URL' => "http://localhost:{$webPort}",
            'SMTPS_HOST_PORT' => (string)(14465 + $offset),
            'SUBMISSION_HOST_PORT' => (string)(14587 + $offset),
            'MAILPIT_HOST_PORT' => (string)(18025 + $offset),
            'MAILPIT_SECONDARY_HOST_PORT' => (string)(18026 + $offset),
            'HOST_USER_ID' => (string)posix_getuid(),
            'HOST_GROUP_ID' => (string)posix_getgid(),
        ];

        $this->m_rootDir = $rootDir;
        $this->m_name = $name;
        $this->m_environment = array_merge(self::readEnvFile("{$rootDir}/tests/test.env"), $added, $settings);
    }


    /**
     * Create the stack's folder, with its settings, settings.yaml and empty logs and state, and start it, removing
     * anything left of a stack of the same name first. Waits until every process in its app container is running, and
     * its web interface answers.
     *
     * @return void
     *
     * @throws RuntimeException - if it doesn't start. The reason is in its compose.log, or its app's logs.
     */
    public function start() : void
    {
        $this->remove();

        foreach (['config', 'logs', 'tls', 'state'] as $folder)
        {
            if (!is_dir("{$this->getDir()}/{$folder}") && !mkdir("{$this->getDir()}/{$folder}", 0777, true))
            {
                throw new RuntimeException("Couldn't create {$this->getDir()}/{$folder}.");
            }
        }

        $this->writeSettingsFile();
        $this->writeEnvFile();

        if ($this->runCompose('up', '-d', '--no-build') !== 0)
        {
            throw new RuntimeException("Its stack didn't start: see {$this->getDir()}/compose.log.");
        }

        $this->waitUntilReady();
    }


    /**
     * Run the PHP unit tests inside the stack's app container, one after another, adding what they print to the
     * stack's output.log.
     * @param string ...$scripts - the scripts in tests/unit, without ".php".
     * @return bool - whether every one passed.
     */
    public function runUnitTests(string ...$scripts) : bool
    {
        $passed = true;
        $copy = ['docker', 'cp', "{$this->m_rootDir}/tests/unit/.", "{$this->getAppContainer()}:/tmp/unit-tests"];

        if (Shell::runToLog($copy, $this->getOutputLog()) !== 0)
        {
            $passed = false;
        }
        else
        {
            foreach ($scripts as $script)
            {
                $path = "/tmp/unit-tests/{$script}.php";
                $command = ['docker', 'exec', '-u', 'www-data', $this->getAppContainer(), 'php', $path];

                if (Shell::runToLog($command, $this->getOutputLog()) !== 0)
                {
                    $passed = false;
                }
            }
        }

        return $passed;
    }


    /**
     * Run a Python suite against the stack, from the tests folder, adding what it prints to the stack's output.log.
     * @param string $module - the module, e.g. "integration.e2e".
     * @return bool - whether it passed.
     */
    public function runPythonSuite(string $module) : bool
    {
        $environment = array_merge($this->m_environment, [
            'PATH' => (string)getenv('PATH'),
            'HOME' => (string)getenv('HOME'),
            'TEST_STATE_DIR' => "{$this->getDir()}/state",
            'PYTHONDONTWRITEBYTECODE' => '1',
        ]);

        $command = ['python3', '-m', $module];
        $testsDir = "{$this->m_rootDir}/tests";
        $exitCode = Shell::runToLogWithEnvironment($command, $this->getOutputLog(), $environment, $testsDir);
        return $exitCode === 0;
    }


    /**
     * Remove the stack's containers, network and volumes, if it has any. Its folder is kept, with its logs.
     * @return void
     */
    public function remove() : void
    {
        // Compose needs the env file to know the stack's project name, so a stack that was never started is skipped.
        if (is_file($this->getEnvFile()))
        {
            $this->runCompose('down', '-v', '--remove-orphans');
        }
    }


    /**
     * Wait until every process in the stack's app container is running, and its web interface answers, as in the
     * shell runner before.
     * @return void
     * @throws RuntimeException - if it isn't ready within three minutes.
     */
    private function waitUntilReady() : void
    {
        $isReady = false;
        $tries = 0;
        $url = "{$this->m_environment['PUBLIC_URL']}/login";

        while (!$isReady && $tries < 90)
        {
            $statuses = trim(Shell::capture(['docker', 'exec', $this->getAppContainer(), 'supervisorctl', 'status']));
            $notRunning = preg_grep('/RUNNING/', explode("\n", $statuses), PREG_GREP_INVERT);

            $isReady = (
                   $statuses !== ''
                && count($notRunning ?: []) === 0
                && self::fetchStatusCode($url) === 200
            );

            if (!$isReady)
            {
                $tries++;
                sleep(2);
            }
        }

        if (!$isReady)
        {
            $problem = "Its stack didn't become ready within three minutes";
            throw new RuntimeException("{$problem}: see {$this->getDir()}/logs.");
        }

        // Give the queue workers a moment to connect.
        sleep(3);
    }


    /**
     * Run docker compose for the stack, adding what it prints to the stack's compose.log.
     * @param string ...$arguments - compose's arguments, e.g. "up", "-d".
     * @return int - its exit code.
     */
    private function runCompose(string ...$arguments) : int
    {
        $command = [
            'docker', 'compose',
            '--project-directory', $this->m_rootDir,
            '--env-file', $this->getEnvFile(),
            '-f', "{$this->m_rootDir}/docker-dev-compose.yml",
            '-f', "{$this->m_rootDir}/tests/docker-compose.tests.yml",
            ...$arguments,
        ];

        return Shell::runToLog($command, "{$this->getDir()}/compose.log");
    }


    /**
     * Write the stack's settings to its env file, for compose, as NAME="value" lines.
     * @return void
     * @throws RuntimeException - if it can't be written.
     */
    /**
     * Write the stack's settings.yaml: tests/settings.yaml, with a retention section from the suite's settings.
     * @throws RuntimeException - if it can't be read or written.
     */
    private function writeSettingsFile() : void
    {
        $settings = @file_get_contents("{$this->m_rootDir}/tests/settings.yaml");

        if ($settings === false)
        {
            throw new RuntimeException("Couldn't read tests/settings.yaml.");
        }

        // How long emails and logs are kept, which some suites change (e.g. to keep emails' content), and their tests
        // read from the environment (as stack.env holds them too).
        $retention = [
            'email_record_days' => $this->m_environment['EMAIL_RECORD_RETENTION_DAYS'] ?? '90',
            'email_content_days' => $this->m_environment['EMAIL_CONTENT_RETENTION_DAYS'] ?? '-1',
            'email_attachment_days' => $this->m_environment['EMAIL_ATTACHMENT_RETENTION_DAYS'] ?? '-1',
            'log_days' => $this->m_environment['LOG_RETENTION_DAYS'] ?? '90',
        ];

        $lines = ['', 'retention:'];

        foreach ($retention as $name => $days)
        {
            $lines[] = "  {$name}: " . (int)$days;
        }

        $path = "{$this->getDir()}/config/settings.yaml";

        if (file_put_contents($path, rtrim($settings) . "\n" . implode("\n", $lines) . "\n") === false)
        {
            throw new RuntimeException("Couldn't write {$path}.");
        }
    }


    private function writeEnvFile() : void
    {
        $lines = [];

        foreach ($this->m_environment as $name => $value)
        {
            $lines[] = "{$name}=\"{$value}\"";
        }

        if (file_put_contents($this->getEnvFile(), implode("\n", $lines) . "\n") === false)
        {
            throw new RuntimeException("Couldn't write {$this->getEnvFile()}.");
        }
    }


    /**
     * Read the settings in an env file of NAME="value" lines, like tests/test.env, ignoring comments and blank lines.
     * @param string $path - the file.
     * @return array<string, string> - the settings, by name.
     * @throws RuntimeException - if it can't be read.
     */
    private static function readEnvFile(string $path) : array
    {
        $lines = @file($path, FILE_IGNORE_NEW_LINES);
        $settings = [];

        if ($lines === false)
        {
            throw new RuntimeException("Couldn't read {$path}.");
        }

        foreach ($lines as $line)
        {
            if (preg_match('/^([A-Z0-9_]+)="?(.*?)"?$/', trim($line), $matches) === 1)
            {
                $settings[$matches[1]] = $matches[2];
            }
        }

        return $settings;
    }


    /**
     * Get the HTTP status code a URL answers with, or 0 if it can't be reached.
     * @param string $url - the URL.
     * @return int
     */
    private static function fetchStatusCode(string $url) : int
    {
        $handle = curl_init($url);
        curl_setopt_array($handle, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5]);
        curl_exec($handle);
        return (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    }


    # Accessors
    public function getName() : string { return $this->m_name; }
    public function getDir() : string { return "{$this->m_rootDir}/tests/.run/{$this->m_name}"; }
    public function getEnvFile() : string { return "{$this->getDir()}/stack.env"; }
    public function getOutputLog() : string { return "{$this->getDir()}/output.log"; }
    public function getAppContainer() : string { return "{$this->m_environment['COMPOSE_PROJECT_NAME']}-app"; }
}
