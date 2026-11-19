<?php

/*
 * The SMTP server that other services send their email through, logging in with an email token's ID as the username
 * and its secret as the password. Every connection is encrypted:
 *  - port 465 (SMTPS) uses TLS from the moment it connects ("implicit TLS", RFC 8314), and
 *  - port 587 (submission) starts in plaintext, but refuses to accept a login (or an email) until the client has
 *    upgraded the connection with STARTTLS (RFC 3207).
 *
 * The server starts as root, only so it can listen on those ports, and then drops to www-data before it accepts a
 * single connection. Each connection is handled in its own forked process, so a slow client (or a slow hand-off to
 * the provider) never holds up the others. At most SMTP_MAX_CONNECTIONS are handled at once: while they're all in use,
 * the server stops accepting, so new connections wait in the operating system's queue (up to LISTEN_BACKLOG on each
 * port) and are handled as soon as one finishes, rather than being refused. When that happens, it's logged as a
 * warning (see SmtpLimitReport), at most once every REPORT_INTERVAL_SECONDS.
 *
 * This parent process never connects to the database, so that no forked child ever shares its connection.
 */

declare(strict_types = 1);


final class SmtpServer
{
    public const int IMPLICIT_TLS_PORT = 465;
    public const int STARTTLS_PORT = 587;
    private const string RUN_AS_USER = 'www-data';

    // How many connections can wait to be accepted on each port, while every connection is in use.
    private const int LISTEN_BACKLOG = 511;

    // How often, at most, connections having to wait is logged.
    private const int REPORT_INTERVAL_SECONDS = 60;

    // How long to wait before checking again for a free connection, while every connection is in use.
    private const int FULL_POLL_MICROSECONDS = 20000;

    private bool $m_isStopping = false;
    private int $m_childCount = 0;

    // Since the last report: how many times connections had to wait, and the longest they did.
    private int $m_timesReached = 0;
    private float $m_longestWaitSeconds = 0.0;

    // When connections started waiting this time (while $m_isWaiting), and when the last report was made.
    private bool $m_isWaiting = false;
    private float $m_waitingSince = 0.0;
    private float $m_lastReportAt = 0.0;


    public function __construct()
    {
    }


    /**
     * Listen for connections until told to stop (SIGTERM or SIGINT).
     *
     * @return void
     *
     * @throws ConfigurationException - if the server can't listen, its TLS certificate can't be read, or it can't
     * drop its root privileges.
     */
    public function run() : void
    {
        $config = Config::get();

        foreach ([$config->getTlsCertFile(), $config->getTlsKeyFile()] as $file)
        {
            if (!is_readable($file))
            {
                $msg = "The SMTP server's TLS file {$file} doesn't exist. docker/prepare-smtp-tls.sh puts it there.";
                throw new ConfigurationException($msg);
            }
        }

        $implicitTlsServer = self::listen(self::IMPLICIT_TLS_PORT);
        $startTlsServer = self::listen(self::STARTTLS_PORT);
        self::dropPrivileges();

        $stop = function () : void {
            $this->m_isStopping = true;
        };

        pcntl_async_signals(true);
        pcntl_signal(SIGTERM, $stop);
        pcntl_signal(SIGINT, $stop);

        $implicitPort = self::IMPLICIT_TLS_PORT;
        $startTlsPort = self::STARTTLS_PORT;
        print "SMTP server listening on {$implicitPort} (implicit TLS) and {$startTlsPort} (STARTTLS)." . PHP_EOL;

        while ($this->m_isStopping === false)
        {
            $this->reapChildren();

            if ($this->m_childCount >= $config->getSmtpMaxConnections())
            {
                // Every connection is in use: leave new ones waiting in the queue until one finishes.
                $this->noteWhetherWaiting($implicitTlsServer, $startTlsServer);
                usleep(self::FULL_POLL_MICROSECONDS);
            }
            else
            {
                $this->acceptConnections($implicitTlsServer, $startTlsServer);
            }
        }

        fclose($implicitTlsServer);
        fclose($startTlsServer);
        print "SMTP server stopped." . PHP_EOL;
    }


    /**
     * Open a listening socket, on IPv6 and IPv4 if possible, falling back to IPv4 only. The TLS certificate is set
     * on it, for the connections it accepts to use. (PHP reads the files each time a connection starts TLS, so a
     * renewed certificate is picked up without a restart.)
     *
     * @param int $port - the port to listen on.
     *
     * @return resource
     *
     * @throws ConfigurationException - if it can't listen at all.
     */
    private static function listen(int $port)
    {
        $config = Config::get();

        $sslOptions = [
            'local_cert' => $config->getTlsCertFile(),
            'local_pk' => $config->getTlsKeyFile(),
            'disable_compression' => true,
            'SNI_enabled' => false,
        ];

        $context = stream_context_create(['ssl' => $sslOptions, 'socket' => ['backlog' => self::LISTEN_BACKLOG]]);
        $flags = STREAM_SERVER_BIND | STREAM_SERVER_LISTEN;
        $server = @stream_socket_server("tcp://[::]:{$port}", $errorCode, $errorMessage, $flags, $context);

        if ($server === false)
        {
            $server = @stream_socket_server("tcp://0.0.0.0:{$port}", $errorCode, $errorMessage, $flags, $context);
        }

        if ($server === false)
        {
            throw new ConfigurationException("The SMTP server couldn't listen on port {$port}: {$errorMessage}");
        }

        return $server;
    }


    /**
     * Stop running as root, now the ports are open. If the server wasn't started as root (e.g. in a test), it
     * carries on as whoever it is.
     *
     * @return void
     *
     * @throws ConfigurationException - if it's root, but can't become www-data.
     */
    private static function dropPrivileges() : void
    {
        if (posix_getuid() === 0)
        {
            $user = posix_getpwnam(self::RUN_AS_USER);

            if (
                   $user === false
                || posix_initgroups(self::RUN_AS_USER, $user['gid']) === false
                || posix_setgid($user['gid']) === false
                || posix_setuid($user['uid']) === false
                || posix_getuid() === 0
            )
            {
                $msg = "The SMTP server couldn't drop its privileges to " . self::RUN_AS_USER . '.';
                throw new ConfigurationException($msg);
            }
        }
    }


    /**
     * Hand a new connection to a forked child process.
     *
     * @param resource $connection - the client's connection.
     *
     * @param string $peerName - the client's address and port, e.g. "10.0.0.5:51234" or "[::1]:51234".
     *
     * @param bool $isImplicitTls - whether it came in on the implicit TLS port, so must start TLS straight away.
     *
     * @return void
     */
    private function handleConnection($connection, string $peerName, bool $isImplicitTls) : void
    {
        if (ServiceFactory::isDbConnected())
        {
            // A bug, not something a client can cause: forking now would let the child's exit end this process's
            // database session (see ServiceFactory::getDb()). Refuse loudly, rather than break in a confusing way.
            fwrite($connection, "421 4.3.0 Temporary server error, try again later\r\n");
            fclose($connection);

            $msg =
                'The SMTP server\'s parent process has a database connection open, so it must not fork. Only the'
                . ' forked children may use the database.';

            throw new LogicException($msg);
        }
        else
        {
            $report = $this->takeReport();
            $pid = pcntl_fork();

            if ($pid === -1)
            {
                fwrite($connection, "421 4.3.0 Temporary server error, try again later\r\n");
                fclose($connection);
            }
            elseif ($pid === 0)
            {
                // The child: handle this one connection, then exit.
                pcntl_signal(SIGTERM, SIG_DFL);
                pcntl_signal(SIGINT, SIG_DFL);
                $exitCode = 0;

                try
                {
                    $report?->log();
                    $clientIp = self::parsePeerAddress($peerName);
                    (new SmtpSession($connection, $clientIp, $isImplicitTls))->run();
                }
                catch (Throwable $e)
                {
                    ServiceFactory::getLogger(LogCategory::EMAIL)->error('SMTP session failed', [
                        'peer' => $peerName,
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                    ]);

                    $exitCode = 1;
                }

                exit($exitCode);
            }
            else
            {
                fclose($connection);
                $this->m_childCount++;
            }
        }
    }


    /**
     * Get the IP address from a peer name, which is "address:port", with an IPv6 address in square brackets.
     *
     * @param string $peerName - e.g. "10.0.0.5:51234" or "[2001:db8::1]:51234".
     *
     * @return IpAddress
     *
     * @throws InvalidInputException - if it doesn't contain a valid address.
     */
    private static function parsePeerAddress(string $peerName) : IpAddress
    {
        $address = (preg_match('/^\[([^\]]+)\]/', $peerName, $matches) === 1)
            ? $matches[1]
            : preg_replace('/:\d+$/', '', $peerName);

        return new IpAddress($address);
    }


    /**
     * Accept whichever connections are waiting (or wait up to a second for one), and hand each to a child, while
     * there's room for it.
     *
     * @param resource $implicitTlsServer - the implicit TLS port's listening socket.
     *
     * @param resource $startTlsServer - the STARTTLS port's listening socket.
     *
     * @return void
     */
    private function acceptConnections($implicitTlsServer, $startTlsServer) : void
    {
        $ready = [$implicitTlsServer, $startTlsServer];
        $unused = null;
        $unusedToo = null;

        if (@stream_select($ready, $unused, $unusedToo, 1) > 0)
        {
            foreach ($ready as $server)
            {
                $hasRoom = ($this->m_childCount < Config::get()->getSmtpMaxConnections());
                $connection = $hasRoom ? @stream_socket_accept($server, 0, $peerName) : false;

                if ($connection !== false)
                {
                    $this->handleConnection($connection, (string)$peerName, $server === $implicitTlsServer);
                }
            }
        }
    }


    /**
     * While every connection is in use, note whether any new ones are waiting to be accepted, and since when, for
     * the next report (see takeReport()).
     *
     * @param resource $implicitTlsServer - the implicit TLS port's listening socket.
     *
     * @param resource $startTlsServer - the STARTTLS port's listening socket.
     *
     * @return void
     */
    private function noteWhetherWaiting($implicitTlsServer, $startTlsServer) : void
    {
        $ready = [$implicitTlsServer, $startTlsServer];
        $unused = null;
        $unusedToo = null;
        $isWaiting = (@stream_select($ready, $unused, $unusedToo, 0) > 0);

        if ($isWaiting && $this->m_isWaiting === false)
        {
            $this->m_isWaiting = true;
            $this->m_waitingSince = microtime(true);
            $this->m_timesReached++;
        }
    }


    /**
     * Get the report of connections having waited, to log, once some have finished waiting and the last report was
     * long enough ago, and start counting afresh.
     *
     * @return SmtpLimitReport|null - the report, or null if there's nothing to report yet. The parent process can't
     * log it itself, as it never connects to the database, so it's handed to the next child.
     */
    private function takeReport() : ?SmtpLimitReport
    {
        if ($this->m_isWaiting)
        {
            // A connection is being accepted, so the wait is over.
            $waited = microtime(true) - $this->m_waitingSince;
            $this->m_longestWaitSeconds = max($this->m_longestWaitSeconds, $waited);
            $this->m_isWaiting = false;
        }

        $isDue = (
               $this->m_timesReached > 0
            && microtime(true) - $this->m_lastReportAt >= self::REPORT_INTERVAL_SECONDS
        );

        $report = null;

        if ($isDue)
        {
            $maxConnections = Config::get()->getSmtpMaxConnections();
            $report = new SmtpLimitReport($maxConnections, $this->m_timesReached, $this->m_longestWaitSeconds);
            $this->m_timesReached = 0;
            $this->m_longestWaitSeconds = 0.0;
            $this->m_lastReportAt = microtime(true);
        }

        return $report;
    }


    private function reapChildren() : void
    {
        while (pcntl_waitpid(-1, $status, WNOHANG) > 0)
        {
            $this->m_childCount--;
        }
    }
}
