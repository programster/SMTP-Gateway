<?php

/*
 * How to connect to an upstream SMTP server.
 */

declare(strict_types = 1);


readonly class SmtpRelaySettings
{
    private string $m_host;
    private int $m_port;
    private string $m_username;
    private string $m_password;
    private SmtpSecurity $m_security;


    /**
     * @param string $host - the server's hostname, e.g. "smtp.sendgrid.net".
     *
     * @param int $port - the port, e.g. 587.
     *
     * @param string $username - the username to log in with, or an empty string to not log in.
     *
     * @param string $password - the password to log in with. Ignored if $username is empty.
     *
     * @param SmtpSecurity $security - how to secure the connection.
     *
     * @throws ConfigurationException - if the host is empty, or the port is out of range.
     */
    public function __construct(string $host, int $port, string $username, string $password, SmtpSecurity $security)
    {
        if (trim($host) === '')
        {
            throw new ConfigurationException('The upstream SMTP host must be set.');
        }

        if ($port < 1 || $port > 65535)
        {
            throw new ConfigurationException("The upstream SMTP port must be from 1 to 65535, not {$port}.");
        }

        $this->m_host = trim($host);
        $this->m_port = $port;
        $this->m_username = $username;
        $this->m_password = $password;
        $this->m_security = $security;
    }


    # Accessors
    public function getHost() : string { return $this->m_host; }
    public function getPort() : int { return $this->m_port; }
    public function getUsername() : string { return $this->m_username; }
    public function getPassword() : string { return $this->m_password; }
    public function getSecurity() : SmtpSecurity { return $this->m_security; }
}
