<?php

/*
 * Sends the queued emails, as the rate limits allow. Started by supervisor, QUEUE_WORKERS times over (4 by default).
 * Any number can run at once, as each email is claimed under a lock. Each keeps its connections to the providers
 * open between emails, and is woken by a PostgreSQL NOTIFY as soon as an email is queued, rather than polling.
 */

declare(strict_types = 1);

require_once(__DIR__ . '/../bootstrap.php');


function main() : void
{
    $isStopping = false;

    $stop = function () use (&$isStopping) : void {
        $isStopping = true;
    };

    pcntl_async_signals(true);
    pcntl_signal(SIGTERM, $stop);
    pcntl_signal(SIGINT, $stop);

    $db = ServiceFactory::getDb();
    $db->query('LISTEN "' . MessageDispatcher::NOTIFY_CHANNEL . '"');
    print "Queue worker started (pid " . getmypid() . ")." . PHP_EOL;
    $lastRecovery = 0;

    // Why the providers can't be used, while they can't (e.g. none have been set up yet), so it's only logged once.
    $providersProblem = '';

    while ($isStopping === false)
    {
        // Each pass is a separate piece of work, so nothing left by one that failed can be committed by the next.
        ServiceFactory::discardTransaction();

        try
        {
            if (time() - $lastRecovery >= 60)
            {
                $recovered = MessageDispatcher::recoverStuckMessages();

                if ($recovered > 0)
                {
                    $logger = ServiceFactory::getLogger(LogCategory::EMAIL);
                    $logger->warning("Put {$recovered} stuck emails back in the queue");
                }

                $lastRecovery = time();
            }

            if (MessageDispatcher::processNextQueued() === false)
            {
                // Nothing could be sent. Wait to be woken by a newly queued email, but no longer than a second, as a
                // rate limit's window (or a retry's delay) may have moved on in the meantime.
                waitForNotification($db->getResource(), 1.0);
            }

            if ($providersProblem !== '')
            {
                $message = 'The providers can be used again, so the queued emails are being sent';
                ServiceFactory::getLogger(LogCategory::EMAIL)->notice($message);
                $providersProblem = '';
            }
        }
        catch (ConfigurationException $e)
        {
            // The emails stay queued, and are sent once the providers can be used (e.g. the first is added on the
            // Providers page), which is checked every few seconds.
            if ($e->getMessage() !== $providersProblem)
            {
                $logger = ServiceFactory::getLogger(LogCategory::EMAIL);

                $logger->warning('No emails can be sent until the providers are fixed', [
                    'reason' => $e->getMessage(),
                ]);

                $providersProblem = $e->getMessage();
            }

            waitForNotification($db->getResource(), 5.0);
        }
        catch (Throwable $e)
        {
            ServiceFactory::getLogger(LogCategory::EMAIL)->error('The queue worker hit an error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            sleep(5);
        }
    }

    ProviderRegistry::disconnectAll();
    print "Queue worker stopped." . PHP_EOL;
}


/**
 * Wait until a NOTIFY arrives on the connection, or the timeout passes, then clear any that arrived.
 *
 * @param \PgSql\Connection $connection - the connection that's LISTENing.
 *
 * @param float $timeoutSeconds - the longest to wait.
 *
 * @return void
 */
function waitForNotification(\PgSql\Connection $connection, float $timeoutSeconds) : void
{
    $socket = pg_socket($connection);
    $read = [$socket];
    $write = null;
    $except = null;
    $seconds = (int)floor($timeoutSeconds);
    $microseconds = (int)(($timeoutSeconds - $seconds) * 1000000);

    if (@stream_select($read, $write, $except, $seconds, $microseconds) > 0)
    {
        pg_consume_input($connection);
    }

    while (pg_get_notify($connection) !== false)
    {
        // Each notification just means "there may be something to send", so they're all handled the same.
    }
}


main();
