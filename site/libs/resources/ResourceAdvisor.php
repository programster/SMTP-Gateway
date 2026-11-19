<?php

/*
 * Suggests the resource variables' values for the server the app is on, and warns about current values that don't
 * fit together. Nothing is applied: the suggestions are shown on the Server resources page, to be put in .env by hand.
 *
 * Each variable gets two suggestions (see ResourceSuggestion): one for if every variable is changed to its suggestion,
 * and one for if only it is changed. So someone who keeps one value larger than suggested, because they need it, can
 * still see what the others should be with it.
 *
 * The memory figures for the app's processes were measured with the load test (see "Running on a small server" in the
 * README): the app container used about 115 MB idle, and each process added to that is far smaller than its own
 * resident size, as they share PHP's code and OPcache. A process handling an email holds several copies of it (the
 * request, the decoded email and its parsed parts), which is what PHP_MEMORY_LIMIT has to allow for.
 */

declare(strict_types = 1);


final readonly class ResourceAdvisor
{
    private const int MIB = ResourceVariable::MIB;
    private const int GIB = ResourceVariable::GIB;

    // The memory kept back for the operating system: at least this, or a tenth of the server's, if that's more.
    private const int MIN_OS_RESERVE_BYTES = 128 * self::MIB;

    // The app container with nothing to do: nginx, supervisord, cron, the SMTP server's parent and OPcache.
    private const int APP_IDLE_BYTES = 115 * self::MIB;

    // What each process adds to the app's memory when it's busy with small emails.
    private const int FPM_PROCESS_BYTES = 12 * self::MIB;
    private const int QUEUE_WORKER_BYTES = 12 * self::MIB;
    private const int SMTP_CONNECTION_BYTES = 4 * self::MIB;

    // How many copies of an email a process holds at once, and what PHP needs on top of that. Measured with the
    // attachments test: an SMTP connection receiving a 19 MiB email needs more than 96 MiB, but no more than 112 MiB
    // (about 4 to 4.5 copies), so 5 leaves some room.
    private const int EMAIL_COPIES = 5;
    private const int PHP_OVERHEAD_BYTES = 28 * self::MIB;

    // PHP's fixed memory limit on the command line, for the cron jobs (in docker/php-cli-overrides.ini), and how many
    // copies of an email removing its attachments holds at once (measured at about 2.7, see AttachmentRemover).
    private const int CLI_MEMORY_LIMIT_BYTES = 256 * self::MIB;
    private const int ATTACHMENT_REMOVAL_COPIES = 3;

    // Room for the JSON around an email sent to the API (its addresses, subject and so on).
    private const int JSON_OVERHEAD_BYTES = self::MIB;

    // Each process can open two database connections (its own, and the logger's), and a few are kept spare for the
    // scheduled scripts and for connecting by hand.
    private const int CONNECTIONS_PER_PROCESS = 2;
    private const int SPARE_CONNECTIONS = 10;

    // What each PostgreSQL connection uses, other than for sorting (work_mem).
    private const int DB_CONNECTION_BYTES = self::MIB;

    private ServerResources $m_server;
    private DatabaseLocation $m_location;
    private ResourceValues $m_current;
    private ResourceValues $m_everythingChanged;
    private ResourceSuggestionCollection $m_suggestions;

    /** @var string[] */
    private array $m_warnings;


    /**
     * @param ServerResources $server - the server the app is on.
     *
     * @param DatabaseLocation $location - where PostgreSQL runs.
     *
     * @param ResourceValues $current - the values the service is running with.
     */
    public function __construct(ServerResources $server, DatabaseLocation $location, ResourceValues $current)
    {
        $this->m_server = $server;
        $this->m_location = $location;
        $this->m_current = $current;
        $this->m_everythingChanged = $this->createEverythingChanged();
        $suggestions = [];

        foreach (ResourceVariable::cases() as $variable)
        {
            if ($this->isSuggested($variable))
            {
                $suggestions[] = new ResourceSuggestion(
                    $variable,
                    $current->get($variable),
                    $this->m_everythingChanged->get($variable),
                    $this->suggest($variable, $current)
                );
            }
        }

        $this->m_suggestions = new ResourceSuggestionCollection(...$suggestions);
        $this->m_warnings = $this->createWarnings();
    }


    /**
     * Create it for the server the app is running on, with the values the service is running with. This reads
     * /proc and the container's cgroup files, and queries the database for its settings.
     *
     * @return ResourceAdvisor
     *
     * @throws RuntimeException - if the server's memory or vCPUs can't be read.
     * @throws Exception - if the database can't be queried.
     */
    public static function createUsingRunningService() : ResourceAdvisor
    {
        $server = ServerResources::createUsingSystemFiles();
        $location = DatabaseLocation::createUsingHost(DB_HOST, $server->getDockerHostIp());
        $current = ResourceValues::createUsingConfig(Config::get(), DatabaseLimits::createUsingDatabase());
        return new ResourceAdvisor($server, $location, $current);
    }


    /**
     * Get the memory kept back for the operating system.
     * @return int - bytes.
     */
    public function getOsReserveBytes() : int
    {
        $tenth = intdiv($this->m_server->getTotalMemoryBytes(), 10);
        return max(self::MIN_OS_RESERVE_BYTES, $tenth);
    }


    /**
     * Get the memory PostgreSQL uses on this server, with the current values: DB_MEMORY_LIMIT, or none if it's on
     * another server.
     *
     * @return int - bytes.
     */
    public function getDatabaseMemoryBytes() : int
    {
        return $this->getDatabaseMemoryBytesUsing($this->m_current);
    }


    /**
     * Get the memory left for the app, with the current values.
     * @return int - bytes, which is below zero if there's none.
     */
    public function getAppMemoryBytes() : int
    {
        return $this->getAppMemoryBytesUsing($this->m_current);
    }


    /**
     * Get about how many emails at the size limit the app could handle at once with the current values, if it had
     * little else to do, before it ran out of memory.
     *
     * @return int
     */
    public function getLargeEmailsAtOnce() : int
    {
        $perEmail = self::EMAIL_COPIES * $this->m_current->get(ResourceVariable::SMTP_MAX_MESSAGE_BYTES);
        $spare = $this->getAppMemoryBytes() - self::APP_IDLE_BYTES;
        return max(0, intdiv($spare, $perEmail));
    }


    /**
     * Get the suggested values if every variable is changed to its suggestion, written as they go in .env, e.g.
     * "QUEUE_WORKERS=4", one line each.
     *
     * @return string
     */
    public function getEnvLines() : string
    {
        $lines = [];

        foreach ($this->m_suggestions as $suggestion)
        {
            $variable = $suggestion->getVariable();
            $lines[] = "{$variable->value}={$variable->formatValue($suggestion->getWithEverythingChanged())}";
        }

        return implode("\n", $lines);
    }


    /**
     * Whether a variable is suggested at all. PostgreSQL's memory and settings aren't, when it's on another server.
     *
     * @param ResourceVariable $variable - the variable.
     *
     * @return bool
     */
    private function isSuggested(ResourceVariable $variable) : bool
    {
        $isDatabaseOnly = ($variable->isForDatabase() && $variable !== ResourceVariable::DB_MAX_CONNECTIONS);
        return ($this->m_location->isOnThisServer() || $isDatabaseOnly === false);
    }


    /**
     * Work out every variable's suggestion, in turn, each from the suggestions before it: the size limit (which is
     * kept as it is) and PostgreSQL's memory first, as the rest follow from them. The process counts are worked out
     * together, so they share the memory left for the app fairly, whatever they're set to now.
     *
     * @return ResourceValues
     */
    private function createEverythingChanged() : ResourceValues
    {
        $values = $this->m_current;
        $variables = ResourceVariable::cases();

        $compareSteps = function (ResourceVariable $a, ResourceVariable $b) : int {
            return self::getStep($a) <=> self::getStep($b);
        };

        usort($variables, $compareSteps);

        foreach ($variables as $variable)
        {
            $value = self::isProcessCount($variable)
                ? $this->suggestSharedProcessCount($variable, $values)
                : $this->suggest($variable, $values);

            $values = $values->withValue($variable, $value);
        }

        return $values;
    }


    /**
     * Suggest a variable's value, given the other variables' values.
     *
     * @param ResourceVariable $variable - the variable.
     *
     * @param ResourceValues $basis - the values of the other variables (its own is ignored, but the size limit).
     *
     * @return int - the count, or the number of bytes.
     */
    private function suggest(ResourceVariable $variable, ResourceValues $basis) : int
    {
        $messageBytes = $basis->get(ResourceVariable::SMTP_MAX_MESSAGE_BYTES);
        $databaseBytes = $basis->get(ResourceVariable::DB_MEMORY_LIMIT);
        $workMem = ($databaseBytes >= 4 * self::GIB) ? 4 * self::MIB : 2 * self::MIB;

        return match ($variable) {
            ResourceVariable::QUEUE_WORKERS => $this->suggestProcessCount($variable, $basis),
            ResourceVariable::PHP_FPM_MAX_CHILDREN => $this->suggestProcessCount($variable, $basis),
            ResourceVariable::SMTP_MAX_CONNECTIONS => $this->suggestProcessCount($variable, $basis),
            ResourceVariable::SMTP_MAX_MESSAGE_BYTES => $messageBytes,
            ResourceVariable::HTTP_MAX_BODY_SIZE => self::getRequiredBodyBytes($messageBytes),
            ResourceVariable::PHP_MEMORY_LIMIT => self::getRequiredPhpMemoryBytes($messageBytes),
            ResourceVariable::DB_MEMORY_LIMIT => $this->suggestDatabaseMemory(),
            ResourceVariable::DB_MAX_CONNECTIONS => self::roundUp(self::getRequiredConnections($basis), 10),
            ResourceVariable::DB_SHARED_BUFFERS => self::roundDown(intdiv($databaseBytes, 6), self::MIB),
            ResourceVariable::DB_EFFECTIVE_CACHE_SIZE => self::roundDown(intdiv($databaseBytes * 2, 3), self::MIB),
            ResourceVariable::DB_WORK_MEM => $workMem,
            ResourceVariable::DB_MAINTENANCE_WORK_MEM => self::roundDown(intdiv($databaseBytes, 12), self::MIB),
        };
    }


    /**
     * Suggest one process count, keeping the other two as they are in $basis: its share of the vCPUs, or fewer if
     * the memory left for the app, once the other processes have theirs, is short.
     *
     * @param ResourceVariable $variable - QUEUE_WORKERS, PHP_FPM_MAX_CHILDREN or SMTP_MAX_CONNECTIONS.
     *
     * @param ResourceValues $basis - the values of the other variables.
     *
     * @return int - at least 1.
     */
    private function suggestProcessCount(ResourceVariable $variable, ResourceValues $basis) : int
    {
        $others = $this->getAppMemoryBytesUsing($basis) - self::APP_IDLE_BYTES;

        foreach (ResourceVariable::cases() as $other)
        {
            if (self::isProcessCount($other) && $other !== $variable)
            {
                $others -= $basis->get($other) * self::getProcessBytes($other);
            }
        }

        $fits = intdiv(max(0, $others), self::getProcessBytes($variable));
        return max(1, min($this->getProcessTarget($variable), $fits));
    }


    /**
     * Suggest one process count, when all three are being changed: each gets its share of the vCPUs, and if they
     * wouldn't all fit in the memory left for the app, each is reduced by the same proportion.
     *
     * @param ResourceVariable $variable - QUEUE_WORKERS, PHP_FPM_MAX_CHILDREN or SMTP_MAX_CONNECTIONS.
     *
     * @param ResourceValues $basis - the values of the other variables (the process counts' are ignored).
     *
     * @return int - at least 1.
     */
    private function suggestSharedProcessCount(ResourceVariable $variable, ResourceValues $basis) : int
    {
        $available = max(0, $this->getAppMemoryBytesUsing($basis) - self::APP_IDLE_BYTES);
        $needed = 0;

        foreach (ResourceVariable::cases() as $count)
        {
            if (self::isProcessCount($count))
            {
                $needed += $this->getProcessTarget($count) * self::getProcessBytes($count);
            }
        }

        $share = min(1.0, $available / $needed);
        return max(1, (int)floor($this->getProcessTarget($variable) * $share));
    }


    /**
     * Suggest PostgreSQL's memory: 3/8 of the server's, from 256 MiB to 8 GiB, in steps of 32 MiB.
     * @return int - bytes.
     */
    private function suggestDatabaseMemory() : int
    {
        $share = intdiv($this->m_server->getTotalMemoryBytes() * 3, 8);
        $clamped = min(8 * self::GIB, max(256 * self::MIB, $share));
        return self::roundDown($clamped, 32 * self::MIB);
    }


    /**
     * Get a process count's share of the vCPUs, before memory is taken into account.
     *
     * @param ResourceVariable $variable - QUEUE_WORKERS, PHP_FPM_MAX_CHILDREN or SMTP_MAX_CONNECTIONS.
     *
     * @return int
     *
     * @throws LogicException - if it isn't a process count.
     */
    private function getProcessTarget(ResourceVariable $variable) : int
    {
        $vcpus = $this->m_server->getVcpus();

        return match ($variable) {
            ResourceVariable::QUEUE_WORKERS => min(32, 2 * $vcpus),
            ResourceVariable::PHP_FPM_MAX_CHILDREN => min(64, 4 * $vcpus),
            ResourceVariable::SMTP_MAX_CONNECTIONS => min(500, 15 * $vcpus),
            default => throw new LogicException("{$variable->value} isn't a process count."),
        };
    }


    /**
     * Get the memory PostgreSQL uses on this server: DB_MEMORY_LIMIT, or none if it's on another server.
     *
     * @param ResourceValues $values - the values to use.
     *
     * @return int - bytes.
     */
    private function getDatabaseMemoryBytesUsing(ResourceValues $values) : int
    {
        $limit = $values->get(ResourceVariable::DB_MEMORY_LIMIT);
        return $this->m_location->isOnThisServer() ? $limit : 0;
    }


    /**
     * Get the memory left for the app: the server's, less what's kept for the operating system and PostgreSQL, or
     * the app container's own limit, if it has one and that's less.
     *
     * @param ResourceValues $values - the values to use.
     *
     * @return int - bytes, which is below zero if there's none.
     */
    private function getAppMemoryBytesUsing(ResourceValues $values) : int
    {
        $server = $this->m_server;
        $reserved = $this->getOsReserveBytes() + $this->getDatabaseMemoryBytesUsing($values);
        $left = $server->getTotalMemoryBytes() - $reserved;
        $limit = $server->hasContainerMemoryLimit() ? $server->getContainerMemoryLimitBytes() : $left;
        return min($left, $limit);
    }


    /**
     * Check the current values against each other and the server, and say what doesn't fit, and how to fix it.
     * @return string[]
     */
    private function createWarnings() : array
    {
        $current = $this->m_current;
        $warnings = [];
        $messageBytes = $current->get(ResourceVariable::SMTP_MAX_MESSAGE_BYTES);
        $message = ResourceVariable::SMTP_MAX_MESSAGE_BYTES->describeValue($messageBytes);
        $bodyBytes = $current->get(ResourceVariable::HTTP_MAX_BODY_SIZE);
        $requiredBodyBytes = self::getRequiredBodyBytes($messageBytes);
        $phpBytes = $current->get(ResourceVariable::PHP_MEMORY_LIMIT);
        $requiredPhpBytes = self::getRequiredPhpMemoryBytes($messageBytes);
        $connections = $current->get(ResourceVariable::DB_MAX_CONNECTIONS);
        $requiredConnections = self::getRequiredConnections($current);
        $appBytes = $this->getAppMemoryBytes();
        $busyBytes = self::APP_IDLE_BYTES;

        foreach (ResourceVariable::cases() as $variable)
        {
            if (self::isProcessCount($variable))
            {
                $busyBytes += $current->get($variable) * self::getProcessBytes($variable);
            }
        }

        if ($bodyBytes < $requiredBodyBytes)
        {
            $size = ResourceVariable::HTTP_MAX_BODY_SIZE->formatValue($requiredBodyBytes);

            $warnings[] =
                "HTTP_MAX_BODY_SIZE is too small for emails of up to {$message} (SMTP_MAX_MESSAGE_BYTES), so the API"
                . " would refuse large emails that the SMTP server accepts. Set it to at least {$size}.";
        }

        if ($phpBytes < $requiredPhpBytes)
        {
            $size = ResourceVariable::PHP_MEMORY_LIMIT->formatValue($requiredPhpBytes);

            $warnings[] =
                "PHP_MEMORY_LIMIT is too small for emails of up to {$message} (SMTP_MAX_MESSAGE_BYTES), so a large"
                . " email could fail part way through. Set it to at least {$size}.";
        }

        $cliBytes = self::ATTACHMENT_REMOVAL_COPIES * $messageBytes + self::PHP_OVERHEAD_BYTES;

        if ($cliBytes > self::CLI_MEMORY_LIMIT_BYTES)
        {
            $needed = ResourceVariable::describeBytes($cliBytes);
            $limit = ResourceVariable::describeBytes(self::CLI_MEMORY_LIMIT_BYTES);
            $largest = ResourceVariable::SMTP_MAX_MESSAGE_BYTES->describeValue(self::getLargestEmailForCliBytes());

            $warnings[] =
                "Removing the attachments from emails of up to {$message} (SMTP_MAX_MESSAGE_BYTES) could need about"
                . " {$needed}, but the cron job that does it is limited to {$limit} (in"
                . " docker/php-cli-overrides.ini), so it would fail. Lower SMTP_MAX_MESSAGE_BYTES to at most"
                . " {$largest}, or raise memory_limit in docker/php-cli-overrides.ini and rebuild the image.";
        }

        if ($connections < $requiredConnections)
        {
            $warnings[] =
                "PostgreSQL accepts {$connections} connections, but the service's processes could open"
                . " {$requiredConnections}. Raise DB_MAX_CONNECTIONS (or max_connections, for a database that isn't"
                . " the bundled one), or lower PHP_FPM_MAX_CHILDREN, QUEUE_WORKERS or SMTP_MAX_CONNECTIONS.";
        }

        if ($busyBytes > $appBytes)
        {
            $busy = ResourceVariable::describeBytes($busyBytes);
            $left = ResourceVariable::describeBytes(max(0, $appBytes));

            $warnings[] =
                "When every process is busy, the app needs about {$busy}, but only {$left} is left for it. Lower"
                . " PHP_FPM_MAX_CHILDREN, QUEUE_WORKERS or SMTP_MAX_CONNECTIONS, or give the server more memory.";
        }

        if ($this->m_location->isOnThisServer())
        {
            $databaseBytes = $current->get(ResourceVariable::DB_MEMORY_LIMIT);
            $perConnection = self::DB_CONNECTION_BYTES + $current->get(ResourceVariable::DB_WORK_MEM);
            $neededBytes = $current->get(ResourceVariable::DB_SHARED_BUFFERS) + $connections * $perConnection;

            if ($neededBytes > $databaseBytes)
            {
                $needed = ResourceVariable::describeBytes($neededBytes);
                $limit = ResourceVariable::describeBytes($databaseBytes);

                $warnings[] =
                    "With every connection in use, PostgreSQL could need about {$needed}, more than its {$limit}"
                    . " (DB_MEMORY_LIMIT). Raise DB_MEMORY_LIMIT, or lower DB_SHARED_BUFFERS, DB_WORK_MEM or"
                    . " DB_MAX_CONNECTIONS.";
            }
        }

        return $warnings;
    }


    /**
     * Get when a variable is worked out, when every variable is being changed: after the ones it depends on.
     *
     * @param ResourceVariable $variable - the variable.
     *
     * @return int - a lower step is worked out first.
     */
    private static function getStep(ResourceVariable $variable) : int
    {
        return match ($variable) {
            ResourceVariable::SMTP_MAX_MESSAGE_BYTES, ResourceVariable::DB_MEMORY_LIMIT => 1,
            ResourceVariable::HTTP_MAX_BODY_SIZE, ResourceVariable::PHP_MEMORY_LIMIT => 2,
            ResourceVariable::DB_SHARED_BUFFERS, ResourceVariable::DB_EFFECTIVE_CACHE_SIZE => 2,
            ResourceVariable::DB_WORK_MEM, ResourceVariable::DB_MAINTENANCE_WORK_MEM => 2,
            ResourceVariable::QUEUE_WORKERS, ResourceVariable::PHP_FPM_MAX_CHILDREN => 3,
            ResourceVariable::SMTP_MAX_CONNECTIONS => 3,
            ResourceVariable::DB_MAX_CONNECTIONS => 4,
        };
    }


    private static function isProcessCount(ResourceVariable $variable) : bool
    {
        $counts = [
            ResourceVariable::QUEUE_WORKERS,
            ResourceVariable::PHP_FPM_MAX_CHILDREN,
            ResourceVariable::SMTP_MAX_CONNECTIONS,
        ];

        return in_array($variable, $counts, true);
    }


    /**
     * Get what one process adds to the app's memory.
     *
     * @param ResourceVariable $variable - QUEUE_WORKERS, PHP_FPM_MAX_CHILDREN or SMTP_MAX_CONNECTIONS.
     *
     * @return int - bytes.
     *
     * @throws LogicException - if it isn't a process count.
     */
    private static function getProcessBytes(ResourceVariable $variable) : int
    {
        return match ($variable) {
            ResourceVariable::QUEUE_WORKERS => self::QUEUE_WORKER_BYTES,
            ResourceVariable::PHP_FPM_MAX_CHILDREN => self::FPM_PROCESS_BYTES,
            ResourceVariable::SMTP_MAX_CONNECTIONS => self::SMTP_CONNECTION_BYTES,
            default => throw new LogicException("{$variable->value} isn't a process count."),
        };
    }


    /**
     * Get the smallest request body that fits an email at the size limit: base64 makes it a third larger, and the
     * JSON around it adds a little more. It's rounded up to a whole MiB.
     *
     * @param int $messageBytes - the size limit (SMTP_MAX_MESSAGE_BYTES).
     *
     * @return int - bytes.
     */
    private static function getRequiredBodyBytes(int $messageBytes) : int
    {
        $base64Bytes = intdiv($messageBytes + 2, 3) * 4;
        return self::roundUp($base64Bytes + self::JSON_OVERHEAD_BYTES, self::MIB);
    }


    /**
     * Get the largest SMTP_MAX_MESSAGE_BYTES that the cron job removing attachments can handle, within PHP's fixed
     * memory limit on the command line.
     *
     * @return int - bytes.
     */
    private static function getLargestEmailForCliBytes() : int
    {
        $available = self::CLI_MEMORY_LIMIT_BYTES - self::PHP_OVERHEAD_BYTES;
        return intdiv($available, self::ATTACHMENT_REMOVAL_COPIES);
    }


    /**
     * Get the memory a PHP process needs to handle an email at the size limit, rounded up to a whole MiB.
     *
     * @param int $messageBytes - the size limit (SMTP_MAX_MESSAGE_BYTES).
     *
     * @return int - bytes.
     */
    private static function getRequiredPhpMemoryBytes(int $messageBytes) : int
    {
        return self::roundUp(self::EMAIL_COPIES * $messageBytes + self::PHP_OVERHEAD_BYTES, self::MIB);
    }


    /**
     * Get how many database connections the service's processes could open at once, with some spare.
     *
     * @param ResourceValues $values - the values to use.
     *
     * @return int
     */
    private static function getRequiredConnections(ResourceValues $values) : int
    {
        $processes = 0;

        foreach (ResourceVariable::cases() as $variable)
        {
            if (self::isProcessCount($variable))
            {
                $processes += $values->get($variable);
            }
        }

        return self::CONNECTIONS_PER_PROCESS * $processes + self::SPARE_CONNECTIONS;
    }


    private static function roundUp(int $value, int $step) : int
    {
        return intdiv($value + $step - 1, $step) * $step;
    }


    private static function roundDown(int $value, int $step) : int
    {
        return intdiv($value, $step) * $step;
    }


    # Accessors
    public function getServer() : ServerResources { return $this->m_server; }
    public function getLocation() : DatabaseLocation { return $this->m_location; }
    public function getSuggestions() : ResourceSuggestionCollection { return $this->m_suggestions; }
    public function getEverythingChanged() : ResourceValues { return $this->m_everythingChanged; }

    /** @return string[] */
    public function getWarnings() : array { return $this->m_warnings; }
}
