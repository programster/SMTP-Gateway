<?php

/*
 * The memory and connection settings PostgreSQL is actually running with. They're read from the database itself,
 * rather than from the DB_... variables, so they're right whether it's the bundled database or one elsewhere.
 */

declare(strict_types = 1);


final readonly class DatabaseLimits
{
    private int $m_maxConnections;
    private int $m_sharedBuffersBytes;
    private int $m_effectiveCacheSizeBytes;
    private int $m_workMemBytes;
    private int $m_maintenanceWorkMemBytes;


    /**
     * @throws LogicException - if a value isn't above zero.
     */
    public function __construct(
        int $maxConnections,
        int $sharedBuffersBytes,
        int $effectiveCacheSizeBytes,
        int $workMemBytes,
        int $maintenanceWorkMemBytes
    )
    {
        $sizes = [$sharedBuffersBytes, $effectiveCacheSizeBytes, $workMemBytes, $maintenanceWorkMemBytes];

        if ($maxConnections < 1 || min($sizes) < 1)
        {
            throw new LogicException('Every database limit must be above zero.');
        }

        $this->m_maxConnections = $maxConnections;
        $this->m_sharedBuffersBytes = $sharedBuffersBytes;
        $this->m_effectiveCacheSizeBytes = $effectiveCacheSizeBytes;
        $this->m_workMemBytes = $workMemBytes;
        $this->m_maintenanceWorkMemBytes = $maintenanceWorkMemBytes;
    }


    /**
     * Create them from the settings the database is running with. This queries the database.
     *
     * @return DatabaseLimits
     *
     * @throws Exception - if the database can't be queried.
     */
    public static function createUsingDatabase() : DatabaseLimits
    {
        $query =
            "SELECT current_setting('max_connections')::int AS max_connections,"
            . " pg_size_bytes(current_setting('shared_buffers')) AS shared_buffers,"
            . " pg_size_bytes(current_setting('effective_cache_size')) AS effective_cache_size,"
            . " pg_size_bytes(current_setting('work_mem')) AS work_mem,"
            . " pg_size_bytes(current_setting('maintenance_work_mem')) AS maintenance_work_mem";

        $row = pg_fetch_all(ServiceFactory::getDb()->query($query))[0];

        return new DatabaseLimits(
            (int)$row['max_connections'],
            (int)$row['shared_buffers'],
            (int)$row['effective_cache_size'],
            (int)$row['work_mem'],
            (int)$row['maintenance_work_mem']
        );
    }


    # Accessors
    public function getMaxConnections() : int { return $this->m_maxConnections; }
    public function getSharedBuffersBytes() : int { return $this->m_sharedBuffersBytes; }
    public function getEffectiveCacheSizeBytes() : int { return $this->m_effectiveCacheSizeBytes; }
    public function getWorkMemBytes() : int { return $this->m_workMemBytes; }
    public function getMaintenanceWorkMemBytes() : int { return $this->m_maintenanceWorkMemBytes; }
}
