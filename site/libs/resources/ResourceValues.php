<?php

/*
 * A value for every ResourceVariable: the counts, and the sizes in bytes. It's always complete, so a formula can read
 * any variable from it, and withValue() returns a copy with one of them changed.
 */

declare(strict_types = 1);


final readonly class ResourceValues
{
    /** @var array<string, int> - by the variable's name. */
    private array $m_values;


    /**
     * @param array<string, int> $values - every variable's value, by its name.
     *
     * @throws LogicException - if a variable is missing, or a value isn't above zero.
     */
    private function __construct(array $values)
    {
        foreach (ResourceVariable::cases() as $variable)
        {
            if (($values[$variable->value] ?? 0) < 1)
            {
                throw new LogicException("{$variable->value} must have a value above zero.");
            }
        }

        $this->m_values = $values;
    }


    /**
     * Create the set of default values, which suit a VPS with 1 GB of memory and 2 vCPUs.
     * @return ResourceValues
     */
    public static function createDefaults() : ResourceValues
    {
        $values = [];

        foreach (ResourceVariable::cases() as $variable)
        {
            $values[$variable->value] = $variable->getDefault();
        }

        return new ResourceValues($values);
    }


    /**
     * Create the values the service is running with: the app's from the configuration, and PostgreSQL's from what
     * it's actually running with.
     *
     * @param Config $config - the configuration.
     *
     * @param DatabaseLimits $database - PostgreSQL's settings.
     *
     * @return ResourceValues
     */
    public static function createUsingConfig(Config $config, DatabaseLimits $database) : ResourceValues
    {
        $values = [
            ResourceVariable::QUEUE_WORKERS->value => $config->getQueueWorkers(),
            ResourceVariable::PHP_FPM_MAX_CHILDREN->value => $config->getPhpFpmMaxChildren(),
            ResourceVariable::SMTP_MAX_CONNECTIONS->value => $config->getSmtpMaxConnections(),
            ResourceVariable::SMTP_MAX_MESSAGE_BYTES->value => $config->getMaxMessageBytes(),
            ResourceVariable::HTTP_MAX_BODY_SIZE->value => $config->getHttpMaxBodyBytes(),
            ResourceVariable::PHP_MEMORY_LIMIT->value => $config->getPhpMemoryLimitBytes(),
            ResourceVariable::DB_MEMORY_LIMIT->value => $config->getDbMemoryLimitBytes(),
            ResourceVariable::DB_MAX_CONNECTIONS->value => $database->getMaxConnections(),
            ResourceVariable::DB_SHARED_BUFFERS->value => $database->getSharedBuffersBytes(),
            ResourceVariable::DB_EFFECTIVE_CACHE_SIZE->value => $database->getEffectiveCacheSizeBytes(),
            ResourceVariable::DB_WORK_MEM->value => $database->getWorkMemBytes(),
            ResourceVariable::DB_MAINTENANCE_WORK_MEM->value => $database->getMaintenanceWorkMemBytes(),
        ];

        return new ResourceValues($values);
    }


    /**
     * Get a variable's value.
     * @return int - the count, or the number of bytes.
     */
    public function get(ResourceVariable $variable) : int
    {
        return $this->m_values[$variable->value];
    }


    /**
     * Get a copy with one variable's value changed.
     *
     * @param ResourceVariable $variable - the variable to change.
     *
     * @param int $value - its new value: the count, or the number of bytes.
     *
     * @return ResourceValues
     *
     * @throws LogicException - if the value isn't above zero.
     */
    public function withValue(ResourceVariable $variable, int $value) : ResourceValues
    {
        $values = $this->m_values;
        $values[$variable->value] = $value;
        return new ResourceValues($values);
    }
}
