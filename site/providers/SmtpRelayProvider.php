<?php

/*
 * A provider that relays each email, unchanged, to any upstream SMTP server (e.g. Amazon SES's SMTP interface, a
 * company mail server, or Mailpit while developing). It talks SMTP directly through PHPMailer's SMTP class, rather
 * than building a new email with PHPMailer, so that the email is passed on exactly as the client sent it.
 *
 * The connection is kept open between emails (up to MAX_EMAILS_PER_CONNECTION), as connecting, starting TLS and
 * logging in take far longer than sending an email. A connection that has been idle too long, or turns out to have
 * been closed by the server, is replaced. That's only ever done before the server has accepted anything for the
 * email, so an email is never sent twice because of it.
 */

declare(strict_types = 1);

use PHPMailer\PHPMailer\SMTP;


class SmtpRelayProvider implements SmtpProviderInterface
{
    private const int TIMEOUT_SECONDS = 30;

    // Many providers limit how many emails can be sent on one connection (SendGrid suggests 100).
    private const int MAX_EMAILS_PER_CONNECTION = 100;

    // Servers close idle connections, so one idle for longer than this isn't reused.
    private const int MAX_IDLE_SECONDS = 20;

    private readonly string $m_name;
    private readonly SmtpRelaySettings $m_settings;

    // The open connection, if there is one.
    private ?SMTP $m_connection = null;
    private int $m_emailsOnConnection = 0;
    private int $m_lastUsedAt = 0;


    /**
     * @param string $name - the provider's name in settings.yaml.
     *
     * @param SmtpRelaySettings $settings - how to connect.
     */
    public function __construct(string $name, SmtpRelaySettings $settings)
    {
        $this->m_name = $name;
        $this->m_settings = $settings;
    }


    public function getName() : string
    {
        return $this->m_name;
    }


    public function getType() : ProviderType
    {
        return ProviderType::CUSTOM;
    }


    /**
     * Relay an email to the upstream server.
     *
     * @param OutgoingEmail $email - the email, and who to deliver it to.
     *
     * @return string - the server's ID for the email, if its reply to DATA includes one (e.g. "queued as ABC123"),
     * or an empty string.
     *
     * @throws ProviderUnavailableException - if it couldn't connect or log in.
     *
     * @throws ProviderTemporaryFailureException - if a 4xx reply was given.
     *
     * @throws ProviderPermanentFailureException - if a 5xx reply was given to the sender, the email, or every
     * recipient.
     */
    public function send(OutgoingEmail $email) : string
    {
        $isReused = $this->hasReusableConnection();

        if ($isReused === false)
        {
            $this->disconnect();
            $this->connect();
        }

        try
        {
            $providerMessageId = $this->sendOnConnection($email, $isReused);
        }
        catch (StaleConnectionException)
        {
            // The server had closed the reused connection. Nothing was accepted, so it's safe to try once more.
            $this->disconnect();
            $this->connect();
            $providerMessageId = $this->sendOnConnection($email, false);
        }

        return $providerMessageId;
    }


    public function disconnect() : void
    {
        if ($this->m_connection !== null)
        {
            @$this->m_connection->quit();
            $this->m_connection->close();
            $this->m_connection = null;
        }
    }


    /**
     * Give a provider that builds on this one (e.g. SendGrid) the chance to change the email just before it's
     * sent, e.g. to add a tracking header. This one sends it unchanged.
     *
     * @param OutgoingEmail $email - the email.
     *
     * @return RawEmail - the email to send.
     */
    protected function prepareForSending(OutgoingEmail $email) : RawEmail
    {
        return $email->getRawEmail();
    }


    /**
     * Send an email on the open connection.
     *
     * @param OutgoingEmail $email - the email.
     *
     * @param bool $isReused - whether the connection was used for an earlier email, in which case a failure before
     * anything was accepted means it had gone stale.
     *
     * @return string - the server's ID for the email, or an empty string.
     *
     * @throws StaleConnectionException - if a reused connection had been closed by the server.
     * @throws ProviderTemporaryFailureException
     * @throws ProviderPermanentFailureException
     */
    private function sendOnConnection(OutgoingEmail $email, bool $isReused) : string
    {
        $smtp = $this->m_connection;

        try
        {
            if ($smtp->mail((string)$email->getEnvelopeSender()) === false)
            {
                // No reply code at all (PHPMailer reports it as 0 or '') means the server never answered, so a reused
                // connection had been closed (e.g. the server restarted), rather than the sender being refused.
                if ($isReused && in_array((string)$smtp->getError()['smtp_code'], ['', '0'], true))
                {
                    throw new StaleConnectionException();
                }

                self::throwForLastError($smtp, 'The sender was refused');
            }

            $accepted = 0;
            $refused = [];

            foreach ($email->getRecipients() as $recipient)
            {
                /* @var $recipient EmailAddress */
                if ($smtp->recipient((string)$recipient))
                {
                    $accepted++;
                }
                else
                {
                    $error = $smtp->getError();

                    if (self::isPermanent($error) === false)
                    {
                        self::throwForLastError($smtp, "Recipient {$recipient} was refused");
                    }

                    $refused[] = "{$recipient} ({$error['smtp_code']} {$error['detail']})";
                }
            }

            if ($accepted === 0)
            {
                throw new ProviderPermanentFailureException('Every recipient was refused: ' . implode(', ', $refused));
            }

            if ($smtp->data($this->prepareForSending($email)->toString()) === false)
            {
                self::throwForLastError($smtp, 'The email was refused');
            }

            if (count($refused) > 0)
            {
                // Some, but not all, recipients were refused. The email still went to the rest.
                ServiceFactory::getLogger(LogCategory::EMAIL)->warning('Some recipients were refused by the provider', [
                    'provider' => $this->m_name,
                    'message_id' => $email->getMessageId(),
                    'refused' => $refused,
                ]);
            }

            $this->m_emailsOnConnection++;
            $this->m_lastUsedAt = time();
            $providerMessageId = self::extractQueuedId($smtp->getLastReply());
        }
        catch (ProviderTemporaryFailureException | ProviderPermanentFailureException $e)
        {
            // Leave the connection ready for the next email, or drop it if even that fails.
            if ($smtp->reset() === false)
            {
                $this->disconnect();
            }

            throw $e;
        }

        return $providerMessageId;
    }


    private function hasReusableConnection() : bool
    {
        return (
               $this->m_connection !== null
            && $this->m_connection->connected()
            && $this->m_emailsOnConnection < self::MAX_EMAILS_PER_CONNECTION
            && time() - $this->m_lastUsedAt <= self::MAX_IDLE_SECONDS
        );
    }


    /**
     * Connect, say hello, secure the connection, and log in.
     *
     * @return void
     *
     * @throws ProviderUnavailableException - if any of it fails.
     */
    private function connect() : void
    {
        $settings = $this->m_settings;
        $smtp = new SMTP();
        $smtp->Timeout = self::TIMEOUT_SECONDS;
        $smtp->do_verp = false;
        $isImplicitTls = ($settings->getSecurity() === SmtpSecurity::SSL);
        $host = ($isImplicitTls ? 'ssl://' : '') . $settings->getHost();
        $myHostname = Config::get()->getSmtpServerHostname();
        $where = "{$this->m_name} ({$settings->getHost()}:{$settings->getPort()})";
        $error = '';

        if ($smtp->connect($host, $settings->getPort(), self::TIMEOUT_SECONDS) === false)
        {
            $error = "Couldn't connect to {$where}: {$smtp->getError()['error']}";
        }
        elseif ($smtp->hello($myHostname) === false)
        {
            $error = "{$where} refused EHLO: " . self::describeError($smtp);
        }
        elseif (
               $settings->getSecurity() === SmtpSecurity::TLS
            && ($smtp->startTLS() === false || $smtp->hello($myHostname) === false)
        )
        {
            $error = "Couldn't start TLS with {$where}: " . self::describeError($smtp);
        }
        elseif (
               $settings->getUsername() !== ''
            && $smtp->authenticate($settings->getUsername(), $settings->getPassword()) === false
        )
        {
            // Our configuration is wrong, not the email, so the emails wait in the queue until it's fixed.
            $error = "Couldn't log in to {$where}: " . self::describeError($smtp);
        }

        if ($error !== '')
        {
            $smtp->close();
            throw new ProviderUnavailableException(trim($error));
        }

        $this->m_connection = $smtp;
        $this->m_emailsOnConnection = 0;
        $this->m_lastUsedAt = time();
    }


    /**
     * Throw the right exception for the server's last error.
     *
     * @return never
     *
     * @throws ProviderRateLimitedException - if the reply says its rate limit (or quota) was reached, even a 5xx.
     * @throws ProviderTemporaryFailureException
     * @throws ProviderPermanentFailureException
     */
    private static function throwForLastError(SMTP $smtp, string $context) : never
    {
        $message = "{$context}: " . self::describeError($smtp);
        $error = $smtp->getError();

        if (RateLimitReply::isRateLimit((string)$error['smtp_code'], "{$error['detail']} {$error['error']}"))
        {
            throw new ProviderRateLimitedException($message);
        }

        if (self::isPermanent($smtp->getError()))
        {
            throw new ProviderPermanentFailureException($message);
        }

        throw new ProviderTemporaryFailureException($message);
    }


    private static function describeError(SMTP $smtp) : string
    {
        $error = $smtp->getError();
        return trim("{$error['smtp_code']} {$error['detail']} {$error['error']}");
    }


    /**
     * Whether an SMTP error is permanent (a 5xx reply).
     */
    private static function isPermanent(array $error) : bool
    {
        return str_starts_with((string)$error['smtp_code'], '5');
    }


    /**
     * Get the ID that most servers include in their reply to DATA, e.g. "250 Ok: queued as 9C2Kq0aJQw2", or Exim's
     * (which SMTP2GO runs), e.g. "250 OK id=1xUmcw-FnQW0hPxdkX-Lxmt".
     */
    private static function extractQueuedId(string $reply) : string
    {
        $id = '';

        if (preg_match('/(?:queued as\s+|\bid=)([^\s;,]+)/i', $reply, $matches) === 1)
        {
            $id = $matches[1];
        }

        return $id;
    }
}
