<?php

/*
 * The .env variables that decide how much memory and CPU the service (and its bundled PostgreSQL) uses. Each case's
 * value is the variable's name. When a variable isn't set, its default suits a VPS with 1 GB of memory and 2 vCPUs.
 *
 * The PostgreSQL variables (DB_...) are only read by the compose files, which repeat their defaults, as compose can't
 * read them from here. The app reads what PostgreSQL is actually running with instead (see DatabaseLimits), as that's
 * what matters, and it works for a database that isn't the bundled one too.
 */

declare(strict_types = 1);


enum ResourceVariable : string
{
    public const int MIB = 1024 * 1024;
    public const int GIB = 1024 * self::MIB;

    case QUEUE_WORKERS = 'QUEUE_WORKERS';
    case PHP_FPM_MAX_CHILDREN = 'PHP_FPM_MAX_CHILDREN';
    case SMTP_MAX_CONNECTIONS = 'SMTP_MAX_CONNECTIONS';
    case SMTP_MAX_MESSAGE_BYTES = 'SMTP_MAX_MESSAGE_BYTES';
    case HTTP_MAX_BODY_SIZE = 'HTTP_MAX_BODY_SIZE';
    case PHP_MEMORY_LIMIT = 'PHP_MEMORY_LIMIT';
    case DB_MEMORY_LIMIT = 'DB_MEMORY_LIMIT';
    case DB_MAX_CONNECTIONS = 'DB_MAX_CONNECTIONS';
    case DB_SHARED_BUFFERS = 'DB_SHARED_BUFFERS';
    case DB_EFFECTIVE_CACHE_SIZE = 'DB_EFFECTIVE_CACHE_SIZE';
    case DB_WORK_MEM = 'DB_WORK_MEM';
    case DB_MAINTENANCE_WORK_MEM = 'DB_MAINTENANCE_WORK_MEM';


    /**
     * Read a value, as it's written in .env.
     *
     * @param string $value - e.g. "30", "128M" or "64MB", depending on the variable's format.
     *
     * @return int - the count, or the number of bytes.
     *
     * @throws ConfigurationException - if it isn't written in the variable's format, or is zero.
     */
    public function parseValue(string $value) : int
    {
        $pattern = match ($this->getFormat()) {
            ResourceValueFormat::COUNT => '/^([0-9]{1,9})$/',
            ResourceValueFormat::BYTES => '/^([0-9]{1,15})$/',
            ResourceValueFormat::SIZE => '/^([0-9]{1,9})([MG])$/i',
            ResourceValueFormat::POSTGRES_SIZE => '/^([0-9]{1,12})(kB|MB|GB)$/',
        };

        $example = match ($this->getFormat()) {
            ResourceValueFormat::COUNT => 'a whole number above zero, e.g. "30"',
            ResourceValueFormat::BYTES => 'a number of bytes above zero, e.g. "20971520"',
            ResourceValueFormat::SIZE => 'a size in M or G, e.g. "128M" or "2G"',
            ResourceValueFormat::POSTGRES_SIZE => 'a size in kB, MB or GB, e.g. "64MB"',
        };

        $isMatch = (preg_match($pattern, trim($value), $matches) === 1);
        $number = $isMatch ? (int)$matches[1] : 0;
        $unit = $matches[2] ?? '';

        $multiplier = match (strtoupper($unit)) {
            'KB' => 1024,
            'M', 'MB' => self::MIB,
            'G', 'GB' => self::GIB,
            default => 1,
        };

        if ($number < 1)
        {
            throw new ConfigurationException("{$this->value} must be {$example}, not \"{$value}\".");
        }

        return $number * $multiplier;
    }


    /**
     * Write a value as it's written in .env, e.g. "128M" or "2G". A size that isn't a whole number of mebibytes is
     * rounded up to one, as that's the smallest unit the format has (other than PostgreSQL's kB).
     *
     * @param int $value - the count, or the number of bytes.
     *
     * @return string
     */
    public function formatValue(int $value) : string
    {
        $mebibytes = (int)ceil($value / self::MIB);
        $isWholeGibibytes = ($mebibytes > 0 && $mebibytes % 1024 === 0);

        return match ($this->getFormat()) {
            ResourceValueFormat::COUNT, ResourceValueFormat::BYTES => (string)$value,
            ResourceValueFormat::SIZE => $isWholeGibibytes ? ($mebibytes / 1024) . 'G' : "{$mebibytes}M",
            ResourceValueFormat::POSTGRES_SIZE => self::formatPostgresSize($value),
        };
    }


    /**
     * Describe a value for people, e.g. "128 MiB", "30" or "20 MiB (20971520 bytes)".
     *
     * @param int $value - the count, or the number of bytes.
     *
     * @return string
     */
    public function describeValue(int $value) : string
    {
        $size = self::describeBytes($value);

        return match ($this->getFormat()) {
            ResourceValueFormat::COUNT => (string)$value,
            ResourceValueFormat::BYTES => "{$size} ({$value} bytes)",
            ResourceValueFormat::SIZE, ResourceValueFormat::POSTGRES_SIZE => $size,
        };
    }


    public function getFormat() : ResourceValueFormat
    {
        return match ($this) {
            self::QUEUE_WORKERS, self::PHP_FPM_MAX_CHILDREN => ResourceValueFormat::COUNT,
            self::SMTP_MAX_CONNECTIONS, self::DB_MAX_CONNECTIONS => ResourceValueFormat::COUNT,
            self::SMTP_MAX_MESSAGE_BYTES => ResourceValueFormat::BYTES,
            self::HTTP_MAX_BODY_SIZE, self::PHP_MEMORY_LIMIT, self::DB_MEMORY_LIMIT => ResourceValueFormat::SIZE,
            self::DB_SHARED_BUFFERS, self::DB_EFFECTIVE_CACHE_SIZE => ResourceValueFormat::POSTGRES_SIZE,
            self::DB_WORK_MEM, self::DB_MAINTENANCE_WORK_MEM => ResourceValueFormat::POSTGRES_SIZE,
        };
    }


    /**
     * Get the value used when the variable isn't set, which suits a VPS with 1 GB of memory and 2 vCPUs. The DB_...
     * defaults are repeated in the compose files (see the top of this file).
     *
     * @return int - the count, or the number of bytes.
     */
    public function getDefault() : int
    {
        return match ($this) {
            self::QUEUE_WORKERS => 4,
            self::PHP_FPM_MAX_CHILDREN => 8,
            self::SMTP_MAX_CONNECTIONS => 30,
            self::SMTP_MAX_MESSAGE_BYTES => 20 * self::MIB,
            self::HTTP_MAX_BODY_SIZE => 40 * self::MIB,
            self::PHP_MEMORY_LIMIT => 128 * self::MIB,
            self::DB_MEMORY_LIMIT => 384 * self::MIB,
            self::DB_MAX_CONNECTIONS => 100,
            self::DB_SHARED_BUFFERS => 64 * self::MIB,
            self::DB_EFFECTIVE_CACHE_SIZE => 256 * self::MIB,
            self::DB_WORK_MEM => 2 * self::MIB,
            self::DB_MAINTENANCE_WORK_MEM => 32 * self::MIB,
        };
    }


    /**
     * Whether it's about PostgreSQL (its memory limit, or one of its settings), rather than the app.
     * @return bool
     */
    public function isForDatabase() : bool
    {
        return str_starts_with($this->value, 'DB_');
    }


    /**
     * Get what the variable decides, for the Server resources page.
     * @return string
     */
    public function getDescription() : string
    {
        return match ($this) {
            self::QUEUE_WORKERS => 'How many queue workers send emails to the providers.',
            self::PHP_FPM_MAX_CHILDREN => 'The most web interface and API requests handled at once.',
            self::SMTP_MAX_CONNECTIONS => 'The most SMTP connections handled at once. More wait their turn.',
            self::SMTP_MAX_MESSAGE_BYTES => 'The largest email the SMTP server and the API accept.',
            self::HTTP_MAX_BODY_SIZE => 'The largest request the web server and PHP accept.',
            self::PHP_MEMORY_LIMIT => 'The most memory one PHP process can use.',
            self::DB_MEMORY_LIMIT => 'The most memory PostgreSQL can use.',
            self::DB_MAX_CONNECTIONS => 'The most connections PostgreSQL accepts.',
            self::DB_SHARED_BUFFERS => 'The memory PostgreSQL keeps tables and indexes in.',
            self::DB_EFFECTIVE_CACHE_SIZE => 'How much PostgreSQL expects to be cached, for planning queries.',
            self::DB_WORK_MEM => 'The memory each sort or hash in a query can use.',
            self::DB_MAINTENANCE_WORK_MEM => 'The memory for vacuuming and building indexes.',
        };
    }


    /**
     * Get how the suggested value is worked out, for the Server resources page.
     * @return string
     */
    public function getHowSuggested() : string
    {
        return match ($this) {
            self::QUEUE_WORKERS => '2 per vCPU, fewer if the memory left for the app is short.',
            self::PHP_FPM_MAX_CHILDREN => '4 per vCPU, fewer if the memory left for the app is short.',
            self::SMTP_MAX_CONNECTIONS => '15 per vCPU, fewer if the memory left for the app is short.',
            self::SMTP_MAX_MESSAGE_BYTES => 'Your choice. The memory and body size limits follow from it.',
            self::HTTP_MAX_BODY_SIZE => 'The largest email, base64-encoded (a third larger), plus 1 MiB for the JSON.',
            self::PHP_MEMORY_LIMIT => '5 copies of the largest email, plus 28 MiB for PHP itself.',
            self::DB_MEMORY_LIMIT => '3/8 of the server\'s memory, from 256 MiB to 8 GiB.',
            self::DB_MAX_CONNECTIONS => '2 for each PHP-FPM process, queue worker and SMTP connection, plus 10.',
            self::DB_SHARED_BUFFERS => '1/6 of PostgreSQL\'s memory.',
            self::DB_EFFECTIVE_CACHE_SIZE => '2/3 of PostgreSQL\'s memory.',
            self::DB_WORK_MEM => '2 MiB, or 4 MiB once PostgreSQL has 4 GiB or more.',
            self::DB_MAINTENANCE_WORK_MEM => '1/12 of PostgreSQL\'s memory.',
        };
    }


    /**
     * Describe a number of bytes for people, e.g. "128 MiB" or "1.5 GiB".
     *
     * @param int $bytes - the number of bytes.
     *
     * @return string
     */
    public static function describeBytes(int $bytes) : string
    {
        $mebibytes = round($bytes / self::MIB, 1);
        return ($mebibytes >= 1024) ? round($mebibytes / 1024, 2) . ' GiB' : "{$mebibytes} MiB";
    }


    private static function formatPostgresSize(int $bytes) : string
    {
        $kibibytes = (int)ceil($bytes / 1024);

        return match (true) {
            $kibibytes % (1024 * 1024) === 0 => ($kibibytes / (1024 * 1024)) . 'GB',
            $kibibytes % 1024 === 0 => ($kibibytes / 1024) . 'MB',
            default => "{$kibibytes}kB",
        };
    }
}
