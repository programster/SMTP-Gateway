<?php

/*
 * The options run.php was given, by run-tests.sh: how many stacks to run at once, whether to keep them running at the
 * end, and whether to run the load test too.
 */

declare(strict_types = 1);


final readonly class RunOptions
{
    private const DEFAULT_WORKERS = 12;

    private int $m_numWorkers;
    private bool $m_isKeepingStacks;
    private bool $m_isLoadTesting;


    /**
     * @param int $numWorkers - how many stacks to run at once, at least 1.
     * @param bool $isKeepingStacks - whether to leave each stack running once its suite has run.
     * @param bool $isLoadTesting - whether to run the load test too.
     * @throws InvalidArgumentException - if $numWorkers is less than 1.
     */
    private function __construct(int $numWorkers, bool $isKeepingStacks, bool $isLoadTesting)
    {
        if ($numWorkers < 1)
        {
            throw new InvalidArgumentException("There must be at least 1 worker, not {$numWorkers}.");
        }

        $this->m_numWorkers = $numWorkers;
        $this->m_isKeepingStacks = $isKeepingStacks;
        $this->m_isLoadTesting = $isLoadTesting;
    }


    /**
     * Create the options from run.php's arguments: --workers=N, --keep and --load.
     * @param string ...$arguments - the arguments, without the script's name.
     * @return RunOptions
     * @throws InvalidArgumentException - for an argument that isn't one of those, or a number of workers that isn't.
     */
    public static function createFromArguments(string ...$arguments) : RunOptions
    {
        $numWorkers = self::DEFAULT_WORKERS;
        $isKeepingStacks = false;
        $isLoadTesting = false;

        foreach ($arguments as $argument)
        {
            if ($argument === '--keep')
            {
                $isKeepingStacks = true;
            }
            elseif ($argument === '--load')
            {
                $isLoadTesting = true;
            }
            elseif (preg_match('/^--workers=([0-9]+)$/', $argument, $matches) === 1)
            {
                $numWorkers = (int)$matches[1];
            }
            else
            {
                throw new InvalidArgumentException(
                    "Unknown option: {$argument} (expected --workers=N, --keep, --load or --remove)"
                );
            }
        }

        return new RunOptions($numWorkers, $isKeepingStacks, $isLoadTesting);
    }


    # Accessors
    public function getNumWorkers() : int { return $this->m_numWorkers; }
    public function isKeepingStacks() : bool { return $this->m_isKeepingStacks; }
    public function isLoadTesting() : bool { return $this->m_isLoadTesting; }
}
