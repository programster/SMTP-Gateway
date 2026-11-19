<?php

/*
 * Running commands for the test runner, without a shell in between, so no argument needs quoting.
 */

declare(strict_types = 1);


final class Shell
{
    /**
     * Run a command, adding what it prints (both output and errors) to a log file.
     * @param list<string> $command - the command and its arguments.
     * @param string $logFile - the file to add its output to.
     * @return int - its exit code.
     */
    public static function runToLog(array $command, string $logFile) : int
    {
        return self::run($command, $logFile, null, null);
    }


    /**
     * Run a command with an environment of its own, from a folder, adding what it prints to a log file.
     *
     * @param list<string> $command - the command and its arguments.
     *
     * @param string $logFile - the file to add its output to.
     *
     * @param array<string, string> $environment - its whole environment, by name. Nothing else is passed on.
     *
     * @param string $workingDir - the folder to run it from.
     *
     * @return int - its exit code.
     */
    public static function runToLogWithEnvironment(
        array $command,
        string $logFile,
        array $environment,
        string $workingDir
    ) : int
    {
        return self::run($command, $logFile, $environment, $workingDir);
    }


    /**
     * Run a command, and get what it prints (both output and errors).
     * @param list<string> $command - the command and its arguments.
     * @return string - what it printed, or an empty string if it couldn't be run.
     */
    public static function capture(array $command) : string
    {
        $output = '';
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes);

        if (is_resource($process))
        {
            $output = (string)stream_get_contents($pipes[1]);
            fclose($pipes[1]);
            proc_close($process);
        }

        return $output;
    }


    /**
     * Run a command, adding what it prints to a log file.
     * @param list<string> $command - the command and its arguments.
     * @param string $logFile - the file to add its output to.
     * @param array<string, string>|null $environment - its environment, or null to pass this process's on.
     * @param string|null $workingDir - the folder to run it from, or null for this process's.
     * @return int - its exit code, or 127 if it couldn't be run.
     */
    private static function run(array $command, string $logFile, ?array $environment, ?string $workingDir) : int
    {
        $exitCode = 127;
        $descriptors = [0 => ['file', '/dev/null', 'r'], 1 => ['file', $logFile, 'a'], 2 => ['redirect', 1]];
        $process = proc_open($command, $descriptors, $pipes, $workingDir, $environment);

        if (is_resource($process))
        {
            $exitCode = proc_close($process);
        }

        return $exitCode;
    }
}
