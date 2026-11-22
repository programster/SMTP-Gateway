<?php

/*
 * One client's SMTP conversation (RFC 5321), from the greeting to QUIT. It supports EHLO/HELO, STARTTLS, AUTH PLAIN
 * and AUTH LOGIN, MAIL FROM, RCPT TO, DATA, RSET, NOOP, VRFY and QUIT.
 *
 * The connection is always encrypted before a login is accepted: on the implicit TLS port it's encrypted from the
 * start, and on the STARTTLS port AUTH is refused until STARTTLS has been used. (And since MAIL needs a login,
 * no email can be sent unencrypted either.)
 *
 * Restrictions are enforced as early as SMTP allows, as clients expect: the token and IP address at AUTH, the
 * envelope sender at MAIL FROM, each recipient at RCPT TO, and the From/Sender/Reply-To headers after DATA.
 *
 * The reply to DATA says whether the email was sent straight away or queued, e.g.
 * "250 2.0.0 Ok: sent as <id>" or "250 2.0.0 Ok: queued as <id>". Both mean it was accepted, so a client never
 * needs to retry it. SMTP has no standard way to tell them apart, so a client that cares must read the text.
 */

declare(strict_types = 1);


final class SmtpSession
{
    private const int TIMEOUT_SECONDS = 300;
    private const int MAX_COMMAND_LENGTH = 2048;
    private const int MAX_RECIPIENTS = 1000;
    private const int MAX_FAILED_LOGINS = 3;

    /** @var resource */
    private $m_connection;

    private readonly IpAddress $m_clientIp;
    private readonly bool $m_isImplicitTls;
    private readonly string $m_hostname;
    private bool $m_isOpen = true;
    private bool $m_hasGreeted = false;
    private bool $m_isTlsActive = false;
    private int $m_failedLogins = 0;

    // The token the client logged in with. Unset until it does (and again after STARTTLS).
    private ?AuthenticatedEmailToken $m_emailToken = null;

    // The current mail transaction (MAIL FROM, then RCPT TO, then DATA).
    private ?EmailAddress $m_envelopeSender = null;
    private EmailAddressCollection $m_recipients;


    /**
     * @param resource $connection - the client's connection.
     *
     * @param IpAddress $clientIp - where the client is connecting from.
     *
     * @param bool $isImplicitTls - whether the connection must start TLS straight away (port 465), rather than
     * being upgraded later with STARTTLS (port 587).
     */
    public function __construct($connection, IpAddress $clientIp, bool $isImplicitTls)
    {
        $this->m_connection = $connection;
        $this->m_clientIp = $clientIp;
        $this->m_isImplicitTls = $isImplicitTls;
        $this->m_hostname = Config::get()->getSmtpServerHostname();
        $this->m_recipients = new EmailAddressCollection();
    }


    /**
     * Hold the conversation until the client quits, disconnects or times out.
     *
     * @return void
     */
    public function run() : void
    {
        stream_set_timeout($this->m_connection, self::TIMEOUT_SECONDS);

        if ($this->m_isImplicitTls)
        {
            // A client that doesn't speak TLS (or gives up on the handshake) is simply disconnected.
            $this->m_isTlsActive = $this->startTls();
            $this->m_isOpen = $this->m_isTlsActive;
        }

        if ($this->m_isOpen)
        {
            $this->reply(220, "{$this->m_hostname} ESMTP " . SERVICE_NAME);
        }

        while ($this->m_isOpen)
        {
            $line = $this->readLine(self::MAX_COMMAND_LENGTH);

            if ($line === null)
            {
                $this->m_isOpen = false;
            }
            else
            {
                $this->handleCommand($line);
            }
        }

        @fclose($this->m_connection);
    }


    /**
     * Handle one command line.
     *
     * @param string $line - the line, without its line ending.
     *
     * @return void
     */
    private function handleCommand(string $line) : void
    {
        // Each command is a separate piece of work, so nothing left by one that failed can be committed by the next.
        ServiceFactory::discardTransaction();
        $parts = explode(' ', $line, 2);
        $verb = strtoupper($parts[0]);
        $argument = trim($parts[1] ?? '');

        try
        {
            match ($verb) {
                'EHLO' => $this->handleEhlo($argument),
                'HELO' => $this->handleHelo($argument),
                'STARTTLS' => $this->handleStartTls(),
                'AUTH' => $this->handleAuth($argument),
                'MAIL' => $this->handleMail($argument),
                'RCPT' => $this->handleRcpt($argument),
                'DATA' => $this->handleData(),
                'RSET' => $this->handleRset(),
                'NOOP' => $this->reply(250, '2.0.0 Ok'),
                'VRFY' => $this->reply(252, '2.5.2 Cannot verify the user, but will accept the message'),
                'HELP' => $this->reply(214, '2.0.0 See https://www.rfc-editor.org/rfc/rfc5321'),
                'QUIT' => $this->handleQuit(),
                default => $this->reply(500, '5.5.2 Command not recognised'),
            };
        }
        catch (Throwable $e)
        {
            ServiceFactory::getLogger(LogCategory::EMAIL)->error('SMTP command failed', [
                'command' => $verb,
                'client_ip' => (string)$this->m_clientIp,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $this->resetTransaction();
            $this->reply(451, '4.3.0 Temporary server error, please try again later');
        }
    }


    private function handleEhlo(string $argument) : void
    {
        if ($argument === '')
        {
            $this->reply(501, '5.5.4 EHLO requires a domain or address');
        }
        else
        {
            $this->m_hasGreeted = true;
            $this->resetTransaction();
            $lines = [$this->m_hostname, 'SIZE ' . Config::get()->getMaxMessageBytes(), '8BITMIME', 'PIPELINING'];

            if ($this->m_isTlsActive === false)
            {
                $lines[] = 'STARTTLS';
            }

            if ($this->canAuthenticate())
            {
                $lines[] = 'AUTH PLAIN LOGIN';
            }

            $lines[] = 'ENHANCEDSTATUSCODES';
            $this->replyMultiline(250, ...$lines);
        }
    }


    private function handleHelo(string $argument) : void
    {
        if ($argument === '')
        {
            $this->reply(501, '5.5.4 HELO requires a domain or address');
        }
        else
        {
            $this->m_hasGreeted = true;
            $this->resetTransaction();
            $this->reply(250, $this->m_hostname);
        }
    }


    private function handleStartTls() : void
    {
        if ($this->m_isTlsActive)
        {
            $this->reply(503, '5.5.1 TLS is already active');
        }
        else
        {
            $this->reply(220, '2.0.0 Ready to start TLS');

            if ($this->startTls())
            {
                // RFC 3207: forget everything from before TLS, and wait for a new EHLO.
                $this->m_isTlsActive = true;
                $this->m_hasGreeted = false;
                $this->m_emailToken = null;
                $this->resetTransaction();
            }
            else
            {
                $this->m_isOpen = false;
            }
        }
    }


    private function handleAuth(string $argument) : void
    {
        $parts = preg_split('/\s+/', $argument, 2);
        $mechanism = strtoupper($parts[0]);
        $initialResponse = $parts[1] ?? '';

        if ($this->m_hasGreeted === false)
        {
            $this->reply(503, '5.5.1 Send EHLO first');
        }
        elseif ($this->m_emailToken !== null)
        {
            $this->reply(503, '5.5.1 Already authenticated');
        }
        elseif ($this->canAuthenticate() === false)
        {
            $this->reply(538, '5.7.11 Encryption required: use STARTTLS first');
        }
        elseif ($mechanism === 'PLAIN')
        {
            $this->handleAuthPlain($initialResponse);
        }
        elseif ($mechanism === 'LOGIN')
        {
            $this->handleAuthLogin($initialResponse);
        }
        else
        {
            $this->reply(504, '5.5.4 Unrecognised authentication mechanism. Use PLAIN or LOGIN');
        }
    }


    /**
     * AUTH PLAIN (RFC 4616): one base64 string of "authorisation-id NUL username NUL password".
     *
     * @param string $initialResponse - the string, if given on the AUTH line.
     *
     * @return void
     */
    private function handleAuthPlain(string $initialResponse) : void
    {
        if ($initialResponse === '')
        {
            $this->reply(334, '');
            $initialResponse = (string)$this->readLine(self::MAX_COMMAND_LENGTH);
        }

        $decoded = base64_decode($initialResponse, true);
        $fields = ($decoded === false) ? [] : explode("\0", $decoded);

        if ($initialResponse === '*')
        {
            $this->reply(501, '5.0.0 Authentication cancelled');
        }
        elseif (count($fields) !== 3)
        {
            $this->reply(501, '5.5.2 Invalid AUTH PLAIN response');
        }
        else
        {
            $this->authenticate($fields[1], $fields[2]);
        }
    }


    /**
     * AUTH LOGIN: the username and password, each base64 encoded, each in reply to a prompt.
     *
     * @param string $initialResponse - the username, if given on the AUTH line.
     *
     * @return void
     */
    private function handleAuthLogin(string $initialResponse) : void
    {
        if ($initialResponse === '')
        {
            $this->reply(334, base64_encode('Username:'));
            $initialResponse = (string)$this->readLine(self::MAX_COMMAND_LENGTH);
        }

        $username = base64_decode($initialResponse, true);

        if ($initialResponse === '*' || $username === false)
        {
            $this->reply(501, '5.0.0 Authentication cancelled');
        }
        else
        {
            $this->reply(334, base64_encode('Password:'));
            $passwordLine = (string)$this->readLine(self::MAX_COMMAND_LENGTH);
            $password = base64_decode($passwordLine, true);

            if ($passwordLine === '*' || $password === false)
            {
                $this->reply(501, '5.0.0 Authentication cancelled');
            }
            else
            {
                $this->authenticate($username, $password);
            }
        }
    }


    private function authenticate(string $username, string $password) : void
    {
        try
        {
            $clientIp = $this->m_clientIp;
            $this->m_emailToken = EmailTokenAuthenticator::authenticateSmtpLogin($username, $password, $clientIp);
            $this->reply(235, '2.7.0 Authentication successful');
        }
        catch (RequestRejectedException $e)
        {
            $this->m_failedLogins++;

            if ($this->m_failedLogins >= self::MAX_FAILED_LOGINS)
            {
                $this->reply(421, '4.7.0 Too many failed logins, closing connection');
                $this->m_isOpen = false;
            }
            else
            {
                $this->reply(535, '5.7.8 ' . $e->getMessage());
            }
        }
    }


    private function handleMail(string $argument) : void
    {
        if ($this->m_emailToken === null)
        {
            $this->reply(530, '5.7.0 Authentication required');
        }
        elseif ($this->m_envelopeSender !== null)
        {
            $this->reply(503, '5.5.1 A mail transaction is already in progress');
        }
        elseif (preg_match('/^FROM:\s*<([^>]*)>(.*)$/i', $argument, $matches) !== 1)
        {
            $this->reply(501, '5.5.4 Syntax: MAIL FROM:<address>');
        }
        elseif (trim($matches[1]) === '')
        {
            $this->reply(550, '5.7.1 A null sender (MAIL FROM:<>) is not allowed');
        }
        else
        {
            $parameters = strtoupper(trim($matches[2]));
            $maxBytes = Config::get()->getMaxMessageBytes();

            if (preg_match('/(?:^|\s)SIZE=(\d+)/', $parameters, $sizeMatches) === 1 && (int)$sizeMatches[1] > $maxBytes)
            {
                $this->reply(552, "5.3.4 The email is larger than the limit of {$maxBytes} bytes");
            }
            else
            {
                $this->acceptAddress($matches[1], AddressRuleField::SENDER);
            }
        }
    }


    private function handleRcpt(string $argument) : void
    {
        if ($this->m_envelopeSender === null)
        {
            $this->reply(503, '5.5.1 Send MAIL FROM first');
        }
        elseif (preg_match('/^TO:\s*<([^>]*)>/i', $argument, $matches) !== 1)
        {
            $this->reply(501, '5.5.4 Syntax: RCPT TO:<address>');
        }
        elseif (count($this->m_recipients) >= self::MAX_RECIPIENTS)
        {
            $this->reply(452, '4.5.3 Too many recipients');
        }
        elseif ($this->wouldExceedRecipientLimits())
        {
            // RFC 5321 3.3: a 452 here tells the client to send this email to the recipients accepted so far, and
            // then the rest in another transaction, so a recipient limit splits the email rather than blocking it.
            $this->reply(452, '4.5.3 Too many recipients for the rate limits; send to the rest in another email');
        }
        else
        {
            $this->acceptAddress($matches[1], AddressRuleField::RECIPIENT);
        }
    }


    /**
     * Check an envelope address against the token's rules, and add it to the transaction if allowed.
     *
     * @param string $addressText - the address, as given in MAIL FROM or RCPT TO.
     *
     * @param AddressRuleField $field - SENDER for MAIL FROM, or RECIPIENT for RCPT TO.
     *
     * @return void
     */
    private function acceptAddress(string $addressText, AddressRuleField $field) : void
    {
        try
        {
            $address = new EmailAddress($addressText);
            PolicyEnforcer::checkAddress($this->m_emailToken, $field, $address);

            if ($field === AddressRuleField::SENDER)
            {
                // Refused straight away if no provider would take it. The From and Sender headers are checked too,
                // once they're sent (see MessageDispatcher::submit()).
                PolicyEnforcer::checkSomeProviderTakes($this->m_emailToken, new EmailAddressCollection($address));
                $this->m_envelopeSender = $address;
                $this->reply(250, '2.1.0 Ok');
            }
            else
            {
                $this->m_recipients = $this->m_recipients->merge(new EmailAddressCollection($address));
                $this->reply(250, '2.1.5 Ok');
            }
        }
        catch (InvalidInputException $e)
        {
            $code = ($field === AddressRuleField::SENDER) ? '5.1.7' : '5.1.3';
            $this->reply(553, "{$code} {$e->getMessage()}");
        }
        catch (RequestRejectedException $e)
        {
            $this->reply(550, '5.7.1 ' . $e->getMessage());
        }
    }


    private function handleData() : void
    {
        if ($this->m_envelopeSender === null || count($this->m_recipients) === 0)
        {
            $this->reply(503, '5.5.1 Send MAIL FROM and at least one RCPT TO first');
        }
        else
        {
            $this->reply(354, 'End data with <CR><LF>.<CR><LF>');
            $maxBytes = Config::get()->getMaxMessageBytes();
            $data = $this->readData($maxBytes);

            if ($data === null)
            {
                $this->m_isOpen = false;
            }
            elseif (strlen($data) > $maxBytes)
            {
                $this->reply(552, "5.3.4 The email is larger than the limit of {$maxBytes} bytes");
            }
            else
            {
                $this->submit($data);
            }

            $this->resetTransaction();
        }
    }


    /**
     * Check the email's headers, and hand it to the dispatcher.
     *
     * @param string $data - the email, as received after DATA.
     *
     * @return void
     */
    private function submit(string $data) : void
    {
        try
        {
            try
            {
                $email = new RawEmail($data);
            }
            catch (InvalidInputException $e)
            {
                $this->m_emailToken->reject(RejectionReason::INVALID_MESSAGE, $e->getMessage());
            }

            PolicyEnforcer::checkHeaders($this->m_emailToken, $email);
            $sender = $this->m_envelopeSender;
            $result = MessageDispatcher::submit($this->m_emailToken, $sender, $this->m_recipients, $email);
            $this->reply(250, "2.0.0 Ok: {$result->getOutcome()->value} as {$result->getMessageId()}");
        }
        catch (RequestRejectedException $e)
        {
            $reply = match ($e->getReason()) {
                RejectionReason::MESSAGE_TOO_LARGE => '552 5.3.4',
                RejectionReason::INVALID_MESSAGE => '554 5.6.0',
                RejectionReason::PROVIDER_REJECTED => '554 5.0.0',
                default => '550 5.7.1',
            };

            [$code, $enhancedCode] = explode(' ', $reply);
            $this->reply((int)$code, "{$enhancedCode} {$e->getMessage()}");
        }
    }


    /**
     * Whether one more recipient would put this email over a recipient rate limit, so it could never be sent.
     *
     * @return bool
     */
    private function wouldExceedRecipientLimits() : bool
    {
        $recipientCount = count($this->m_recipients) + 1;
        return MessageDispatcher::describeLimitsExceededByRecipientCount($this->m_emailToken, $recipientCount) !== '';
    }


    private function handleRset() : void
    {
        $this->resetTransaction();
        $this->reply(250, '2.0.0 Ok');
    }


    private function handleQuit() : void
    {
        $this->reply(221, '2.0.0 Bye');
        $this->m_isOpen = false;
    }


    /**
     * Whether a login may be attempted on this connection: only once it's encrypted, so a token's secret is never
     * sent in the clear.
     *
     * @return bool
     */
    private function canAuthenticate() : bool
    {
        return $this->m_isTlsActive;
    }


    /**
     * Do the TLS handshake. Only TLS 1.2 and 1.3 are accepted, as older versions are broken.
     *
     * @return bool - whether it succeeded.
     */
    private function startTls() : bool
    {
        $method = STREAM_CRYPTO_METHOD_TLSv1_2_SERVER | STREAM_CRYPTO_METHOD_TLSv1_3_SERVER;
        return @stream_socket_enable_crypto($this->m_connection, true, $method) === true;
    }


    private function resetTransaction() : void
    {
        $this->m_envelopeSender = null;
        $this->m_recipients = new EmailAddressCollection();
    }


    /**
     * Read one line from the client.
     *
     * @param int $maxLength - the longest line accepted. Longer lines are cut off, and the rest is discarded.
     *
     * @return string|null - the line without its line ending, or null if the client disconnected or timed out.
     */
    private function readLine(int $maxLength) : ?string
    {
        $line = fgets($this->m_connection, $maxLength);
        $result = null;

        if ($line !== false)
        {
            if (!str_ends_with($line, "\n"))
            {
                // Too long: throw away the rest of it.
                while (($rest = fgets($this->m_connection, $maxLength)) !== false && !str_ends_with($rest, "\n"))
                {
                }
            }

            $result = rtrim($line, "\r\n");
        }

        if ($result === null && stream_get_meta_data($this->m_connection)['timed_out'])
        {
            $this->reply(421, '4.4.2 Timeout, closing connection');
        }

        return $result;
    }


    /**
     * Read the email after DATA, up to the line holding just ".", undoing the dot-stuffing (RFC 5321 4.5.2).
     * Once the email is over the size limit, the rest is read but not kept.
     *
     * @param int $maxBytes - the size limit.
     *
     * @return string|null - the email (longer than $maxBytes if it was over the limit), or null if the client
     * disconnected or timed out part way through.
     */
    private function readData(int $maxBytes) : ?string
    {
        $data = '';
        $isComplete = false;
        $isConnected = true;

        while ($isComplete === false && $isConnected)
        {
            $line = fgets($this->m_connection, 65536);

            if ($line === false)
            {
                $isConnected = false;
            }
            elseif ($line === ".\r\n" || $line === ".\n")
            {
                $isComplete = true;
            }
            elseif (strlen($data) <= $maxBytes)
            {
                $data .= str_starts_with($line, '.') ? substr($line, 1) : $line;
            }
        }

        return $isComplete ? $data : null;
    }


    private function reply(int $code, string $text) : void
    {
        @fwrite($this->m_connection, rtrim("{$code} {$text}") . "\r\n");
    }


    private function replyMultiline(int $code, string ...$lines) : void
    {
        $output = '';
        $lastIndex = count($lines) - 1;

        foreach ($lines as $index => $line)
        {
            $separator = ($index === $lastIndex) ? ' ' : '-';
            $output .= "{$code}{$separator}{$line}\r\n";
        }

        @fwrite($this->m_connection, $output);
    }
}
