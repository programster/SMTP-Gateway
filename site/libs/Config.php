<?php

/*
 * The deployment's configuration, read from the environment (the .env file) once, and checked as it's read, so a
 * mistake is reported when the service starts rather than when the setting is first used. These are the settings that
 * can't change while it runs. The service's own settings, which are changed on the Settings page, are in Settings.
 */

declare(strict_types = 1);


readonly class Config
{
    private string $m_environment;
    private string $m_smtpServerHostname;
    private int $m_maxMessageBytes;
    private int $m_queueWorkers;
    private int $m_smtpMaxConnections;
    private int $m_phpFpmMaxChildren;
    private int $m_httpMaxBodyBytes;
    private int $m_phpMemoryLimitBytes;
    private int $m_dbMemoryLimitBytes;
    private string $m_publicUrl;


    /**
     * @throws ConfigurationException - if a setting is missing or invalid.
     */
    private function __construct()
    {
        $this->m_environment = self::getRequiredEnv('ENVIRONMENT');
        $this->m_smtpServerHostname = self::getEnv('SMTP_SERVER_HOSTNAME', (string)gethostname());
        $this->m_maxMessageBytes = self::readResource(ResourceVariable::SMTP_MAX_MESSAGE_BYTES);
        $this->m_queueWorkers = self::readResource(ResourceVariable::QUEUE_WORKERS);
        $this->m_smtpMaxConnections = self::readResource(ResourceVariable::SMTP_MAX_CONNECTIONS);
        $this->m_phpFpmMaxChildren = self::readResource(ResourceVariable::PHP_FPM_MAX_CHILDREN);
        $this->m_httpMaxBodyBytes = self::readResource(ResourceVariable::HTTP_MAX_BODY_SIZE);
        $this->m_phpMemoryLimitBytes = self::readResource(ResourceVariable::PHP_MEMORY_LIMIT);
        $this->m_dbMemoryLimitBytes = self::readResource(ResourceVariable::DB_MEMORY_LIMIT);
        $this->m_publicUrl = self::readPublicUrl($this->m_environment);
    }


    /**
     * Get the configuration. It's read once per process, and then reused.
     *
     * @return Config
     *
     * @throws ConfigurationException - if a setting is missing or invalid.
     */
    public static function get() : Config
    {
        static $config = null;

        if ($config === null)
        {
            $config = new Config();
        }

        return $config;
    }


    /**
     * Get an optional environment variable.
     *
     * @param string $name - the variable's name.
     *
     * @param string $default - what to return if it isn't set, or is empty.
     *
     * @return string
     */
    public static function getEnv(string $name, string $default = '') : string
    {
        $value = getenv($name);
        return ($value === false || trim($value) === '') ? $default : trim($value);
    }


    /**
     * Whether PUBLIC_URL is set, which links in emails, and logging in with Google, need.
     * @return bool
     */
    public function hasPublicUrl() : bool
    {
        return $this->m_publicUrl !== '';
    }


    /**
     * Get the address the web interface is reached at (PUBLIC_URL), for links in emails, e.g. https://smtp.example.org.
     *
     * @return string - with no trailing slash.
     *
     * @throws ConfigurationException - if PUBLIC_URL isn't set.
     */
    public function getPublicUrl() : string
    {
        if ($this->m_publicUrl === '')
        {
            throw new ConfigurationException('PUBLIC_URL isn\'t set.');
        }

        return $this->m_publicUrl;
    }


    public function isDev() : bool
    {
        return $this->m_environment === 'dev';
    }


    /**
     * Get where the SMTP server reads its TLS certificate (full chain) from. docker/prepare-smtp-tls.sh puts it
     * there, copied from SMTP_TLS_CERT_FILE, or self-signed if that isn't set.
     *
     * @return string
     */
    public function getTlsCertFile() : string
    {
        return '/run/smtp-tls/cert.pem';
    }


    /**
     * Get where the SMTP server reads its TLS private key from. See getTlsCertFile().
     *
     * @return string
     */
    public function getTlsKeyFile() : string
    {
        return '/run/smtp-tls/key.pem';
    }


    /**
     * @throws ConfigurationException - if it isn't set.
     */
    private static function getRequiredEnv(string $name) : string
    {
        $value = self::getEnv($name);

        if ($value === '')
        {
            throw new ConfigurationException("The environment variable {$name} must be set.");
        }

        return $value;
    }


    /**
     * Read one of the variables that decide how much memory and CPU the service uses, or its default (which suits
     * a VPS with 1 GB of memory and 2 vCPUs) if it isn't set.
     *
     * @param ResourceVariable $variable - the variable.
     *
     * @return int - the count, or the number of bytes.
     *
     * @throws ConfigurationException - if it's set, but isn't written in its format.
     */
    private static function readResource(ResourceVariable $variable) : int
    {
        $default = $variable->formatValue($variable->getDefault());
        return $variable->parseValue(self::getEnv($variable->value, $default));
    }


    /**
     * Read PUBLIC_URL: the scheme and host (and port, if not the usual one) the web interface is reached at, with
     * nothing after it. It's set, rather than worked out from each request's Host header, as anyone can send any Host
     * header, which would let them make a password reset email link to their own site. It must use https, except in
     * development.
     *
     * @param string $environment - ENVIRONMENT.
     *
     * @return string - with no trailing slash, or an empty string if it isn't set.
     *
     * @throws ConfigurationException - if it isn't valid.
     */
    private static function readPublicUrl(string $environment) : string
    {
        $url = rtrim(self::getEnv('PUBLIC_URL'), '/');
        $scheme = ($environment === 'dev') ? 'https?' : 'https';
        $pattern = "#^{$scheme}://[A-Za-z0-9.-]+(:[0-9]{1,5})?$#";

        if ($url !== '' && preg_match($pattern, $url) !== 1)
        {
            $msg =
                "PUBLIC_URL must be just the https:// address the web interface is reached at, e.g."
                . " https://smtp.example.org, with nothing after it, not \"{$url}\".";

            throw new ConfigurationException($msg);
        }

        return $url;
    }


    # Accessors
    public function getEnvironment() : string { return $this->m_environment; }
    public function getSmtpServerHostname() : string { return $this->m_smtpServerHostname; }
    public function getMaxMessageBytes() : int { return $this->m_maxMessageBytes; }
    public function getQueueWorkers() : int { return $this->m_queueWorkers; }
    public function getSmtpMaxConnections() : int { return $this->m_smtpMaxConnections; }
    public function getPhpFpmMaxChildren() : int { return $this->m_phpFpmMaxChildren; }
    public function getHttpMaxBodyBytes() : int { return $this->m_httpMaxBodyBytes; }
    public function getPhpMemoryLimitBytes() : int { return $this->m_phpMemoryLimitBytes; }
    public function getDbMemoryLimitBytes() : int { return $this->m_dbMemoryLimitBytes; }
}
