<?php

/*
 * The memory and vCPUs of the server the app is running on, as seen from inside its container: the server's own
 * memory (which /proc/meminfo shows even inside a container), any memory limit the app's container has been given,
 * the vCPUs it can use, and the address of the Docker host (the container's default gateway), which tells a database
 * on the same server apart from one on another.
 */

declare(strict_types = 1);

use Programster\CoreLibs\Core;
use Programster\CoreLibs\Enums\DataSizeUnitEnum;
use Programster\CoreLibs\Enums\MemoryTypeEnum;


final readonly class ServerResources
{
    private int $m_totalMemoryBytes;
    private int $m_containerMemoryLimitBytes;
    private int $m_vcpus;
    private string $m_dockerHostIp;


    /**
     * @param int $totalMemoryBytes - the server's memory.
     *
     * @param int $containerMemoryLimitBytes - the app container's memory limit, or 0 if it hasn't been given one.
     * A limit above the server's memory is no limit, so it's taken as 0.
     *
     * @param int $vcpus - the vCPUs the app can use.
     *
     * @param string $dockerHostIp - the Docker host's address, or an empty string if it isn't known.
     *
     * @throws LogicException - if the memory or vCPUs aren't above zero, or the limit is below zero.
     */
    public function __construct(int $totalMemoryBytes, int $containerMemoryLimitBytes, int $vcpus, string $dockerHostIp)
    {
        if ($totalMemoryBytes < 1 || $vcpus < 1 || $containerMemoryLimitBytes < 0)
        {
            throw new LogicException('The memory and vCPUs must be above zero, and a memory limit can\'t be below it.');
        }

        $this->m_totalMemoryBytes = $totalMemoryBytes;
        $isRealLimit = ($containerMemoryLimitBytes < $totalMemoryBytes);
        $this->m_containerMemoryLimitBytes = $isRealLimit ? $containerMemoryLimitBytes : 0;
        $this->m_vcpus = $vcpus;
        $this->m_dockerHostIp = $dockerHostIp;
    }


    /**
     * Create them by reading /proc and the container's cgroup (v2) files.
     *
     * @return ServerResources
     *
     * @throws RuntimeException - if the server's memory or vCPUs can't be read.
     */
    public static function createUsingSystemFiles() : ServerResources
    {
        try
        {
            $totalMemoryBytes = (int)Core::getMemory(MemoryTypeEnum::TOTAL, DataSizeUnitEnum::BYTES);
        }
        catch (Exception $exception)
        {
            throw new RuntimeException('The server\'s memory couldn\'t be read.', 0, $exception);
        }

        $limitFile = '/sys/fs/cgroup/memory.max';
        $limit = is_readable($limitFile) ? trim(self::readFile($limitFile)) : 'max';
        $containerMemoryLimitBytes = ctype_digit($limit) ? (int)$limit : 0;

        return new ServerResources(
            $totalMemoryBytes,
            $containerMemoryLimitBytes,
            self::readVcpus(),
            self::readDockerHostIp()
        );
    }


    /**
     * Whether the app's container has been given a memory limit of its own.
     * @return bool
     */
    public function hasContainerMemoryLimit() : bool
    {
        return $this->m_containerMemoryLimitBytes > 0;
    }


    /**
     * Read the vCPUs the app can use: the processors in /proc/cpuinfo (the server's), capped by the container's CPU
     * quota, if it has one (e.g. "200000 100000" in cpu.max is 2 vCPUs). A part of a vCPU is rounded down, to at
     * least 1.
     *
     * @return int
     *
     * @throws RuntimeException - if /proc/cpuinfo can't be read, or lists no processors.
     */
    private static function readVcpus() : int
    {
        $processors = preg_match_all('/^processor\s*:/m', self::readFile('/proc/cpuinfo'));

        if ($processors < 1)
        {
            throw new RuntimeException('The server\'s vCPUs couldn\'t be read from /proc/cpuinfo.');
        }

        $quotaFile = '/sys/fs/cgroup/cpu.max';
        $quota = is_readable($quotaFile) ? self::readFile($quotaFile) : 'max';
        $hasQuota = (preg_match('/^([0-9]+) ([0-9]+)$/', trim($quota), $matches) === 1 && (int)$matches[2] > 0);
        $quotaVcpus = $hasQuota ? max(1, intdiv((int)$matches[1], (int)$matches[2])) : $processors;

        return min($processors, $quotaVcpus);
    }


    /**
     * Read the address of the container's default gateway, which is the Docker host, from /proc/net/route, where
     * it's written in hex, lowest byte first (e.g. "010011AC" is 172.17.0.1).
     *
     * @return string - or an empty string if there's no default route.
     */
    private static function readDockerHostIp() : string
    {
        $routes = is_readable('/proc/net/route') ? self::readFile('/proc/net/route') : '';
        $pattern = '/^\S+\s+00000000\s+([0-9A-F]{8})\s/mi';
        $hasGateway = (preg_match($pattern, $routes, $matches) === 1);
        $bytes = $hasGateway ? array_reverse(array_map('hexdec', str_split($matches[1], 2))) : [];
        return $hasGateway ? implode('.', $bytes) : '';
    }


    /**
     * @throws RuntimeException - if the file can't be read.
     */
    private static function readFile(string $path) : string
    {
        $contents = @file_get_contents($path);

        if ($contents === false)
        {
            throw new RuntimeException("{$path} couldn't be read.");
        }

        return $contents;
    }


    # Accessors
    public function getTotalMemoryBytes() : int { return $this->m_totalMemoryBytes; }
    public function getContainerMemoryLimitBytes() : int { return $this->m_containerMemoryLimitBytes; }
    public function getVcpus() : int { return $this->m_vcpus; }
    public function getDockerHostIp() : string { return $this->m_dockerHostIp; }
}
