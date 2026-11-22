<?php

/*
 * Accepts emails, and hands them to the providers.
 *
 * Accepting (submit()): the SMTP server or the send endpoint records the email as queued, and wakes the queue workers.
 * If it estimates a worker will start trying to send it within the wait set on the Settings page, it waits for the
 * outcome by watching the email's status in the database, so the client can be told it was sent (or refused).
 * Otherwise, or if it isn't picked up in time, the client is told it was queued.
 *
 * Sending (processNextQueued(), run by the queue workers): a worker claims the oldest email the rate limits allow
 * (its token's, the global ones, and the chosen provider's), choosing the provider by the mode in settings.yaml, and
 * hands it over. Claiming happens while holding a PostgreSQL advisory lock, so any number of workers can never
 * between them send more than the limits allow, or claim the same email.
 *
 * When a provider can't be reached, or fails temporarily, the next provider is tried straight away. In fallback
 * mode, that's also done when a provider rejects the email outright. Once every provider has been tried, the email
 * waits (1, 5 and 15 minutes, then 1 and 4 hours) for another round, and fails after MAX_ROUNDS.
 */

declare(strict_types = 1);

use Programster\PgsqlLib\Exceptions\ExceptionQueryError;
use Programster\PgsqlLib\PgsqlLib;
use Programster\PgsqlLib\UuidVersion;
use Programster\PgsqlObjects\OrmTransaction;


final class MessageDispatcher
{
    // The channel the queue workers LISTEN on, to be woken as soon as there's an email to send.
    public const string NOTIFY_CHANNEL = 'email_queued';

    // An arbitrary number identifying this service's dispatch lock among PostgreSQL advisory locks.
    private const int DISPATCH_LOCK_ID = 727274101;

    // How many rounds of trying every provider before giving up, and how long to wait (in seconds) after each.
    private const int MAX_ROUNDS = 6;
    private const array RETRY_DELAYS_SECONDS = [60, 300, 900, 3600, 14400];

    // An email left "sending" for this long belongs to a process that died, and is put back in the queue.
    private const int STUCK_AFTER_SECONDS = 600;

    // Once a worker has started trying to send an email the client is waiting for, how long to keep waiting for the
    // outcome (long enough for a provider's connection to time out, and the next to be tried).
    private const int MAX_WAIT_AFTER_PICKUP_SECONDS = 75;

    // How often a waiting client's connection checks the email's status.
    private const int POLL_MICROSECONDS = 50000;


    /**
     * Accept an email for sending.
     *
     * @param AuthenticatedEmailToken $emailToken - the token it's being sent with. Its policy must already have been
     * checked.
     *
     * @param EmailAddress $envelopeSender - the envelope sender (MAIL FROM).
     *
     * @param EmailAddressCollection $recipients - who to deliver to (To, Cc and Bcc alike).
     *
     * @param RawEmail $email - the email.
     *
     * @return SubmissionResult - whether it was sent (while the client waited) or queued, and its ID.
     *
     * @throws RequestRejectedException - if it's too large, has more recipients than a rate limit could ever allow, no
     * provider the token may use takes its sender, or every provider refused it while the client waited. It's recorded.
     */
    public static function submit(
        AuthenticatedEmailToken $emailToken,
        EmailAddress $envelopeSender,
        EmailAddressCollection $recipients,
        RawEmail $email
    ) : SubmissionResult
    {
        $maxBytes = Config::get()->getMaxMessageBytes();

        if ($email->getSizeInBytes() > $maxBytes)
        {
            $msg = "The email is larger than the limit of {$maxBytes} bytes.";
            $emailToken->reject(RejectionReason::MESSAGE_TOO_LARGE, $msg);
        }

        self::rejectIfLimitsCanNeverAllow($emailToken, count($recipients));
        $senders = self::readSenderAddresses($emailToken, $envelopeSender, $email);
        PolicyEnforcer::checkSomeProviderTakes($emailToken, $senders);
        $senderStrings = self::toStrings($senders);
        $recipientStrings = self::toStrings($recipients);
        $recipientCount = count($recipientStrings);

        $row = [
            'email_token_id' => $emailToken->getEmailToken()->getId(),
            'source' => $emailToken->getSource()->value,
            'client_ip' => (string)$emailToken->getClientIp(),
            'envelope_sender' => (string)$envelopeSender,
            'sender_addresses' => json_encode($senderStrings),
            'recipients' => json_encode($recipientStrings),
            'recipient_count' => count($recipientStrings),
            'subject' => mb_substr($email->getSubject(), 0, 1000),
            'size_bytes' => $email->getSizeInBytes(),
            'raw_message_base64' => base64_encode($email->toString()),
            'status' => MessageStatus::QUEUED->value,
            'was_queued' => false,
            'attempts' => 0,
            'next_attempt_at' => Time::now(),
            'dispatched_at' => null,
            'created_at' => Time::now(),
            'priority' => $emailToken->getPolicy()->getPriority()->getValue(),
        ];

        $transaction = new OrmTransaction(ServiceFactory::getDb());
        $messageId = self::addMessageToTransaction($transaction, $row);
        $transaction->commit();
        $outcome = SubmissionOutcome::QUEUED;
        $waitSeconds = Settings::get()->getSyncWaitSeconds();

        // The email is queued by now, so if the providers can't be used (e.g. none have been set up yet), the client
        // is told it's queued, rather than that it failed, and it's sent once they can be.
        try
        {
            $isWaiting = (
                   $waitSeconds > 0
                && QueueEstimator::estimateSecondsUntilAttempt($emailToken, $messageId, $recipientCount, $senders)
                    <= $waitSeconds
            );
        }
        catch (ConfigurationException)
        {
            $isWaiting = false;
        }
        catch (NotFoundException $e)
        {
            // It was accepted, so this mustn't reach the client as a failure, which it might retry, sending it twice.
            $isWaiting = false;
            $logger = ServiceFactory::getLogger(LogCategory::EMAIL);
            $logger->error('An email was deleted just after being queued', ['message_id' => $messageId]);
        }

        if ($isWaiting)
        {
            try
            {
                $outcome = self::waitForOutcome($messageId, microtime(true) + $waitSeconds);
            }
            catch (ProviderPermanentFailureException $e)
            {
                $emailToken->reject(RejectionReason::PROVIDER_REJECTED, "The email was refused: {$e->getMessage()}");
            }
        }

        if ($outcome === SubmissionOutcome::QUEUED)
        {
            self::markAsHavingQueued($messageId);
        }

        return new SubmissionResult($messageId, $outcome);
    }


    /**
     * Add one of the service's own emails (e.g. a password reset link) to the transaction being built, with the system
     * priority, so it goes ahead of every token's emails. It still counts towards the global and per-provider limits,
     * and waits for them like any other email. It doesn't wait to be sent.
     *
     * @param EmailAddress $envelopeSender - the envelope sender (the address set on the Settings page).
     *
     * @param EmailAddressCollection $recipients - who to deliver to.
     *
     * @param RawEmail $email - the email.
     *
     * @param string $clientIp - the IP address of whoever caused it to be sent (e.g. asked for the reset).
     *
     * @return void
     */
    public static function addServiceEmailToTransaction(
        EmailAddress $envelopeSender,
        EmailAddressCollection $recipients,
        RawEmail $email,
        string $clientIp
    ) : void
    {
        $recipientStrings = self::toStrings($recipients);

        // The service's own email is built here, so its From header can always be read.
        $senders = $email->getSenderAddresses($envelopeSender);
        $senderStrings = self::toStrings($senders);
        self::warnIfNoProviderTakesSystemEmail($senders);

        $row = [
            'email_token_id' => null,
            'source' => MessageSource::SYSTEM->value,
            'client_ip' => $clientIp,
            'envelope_sender' => (string)$envelopeSender,
            'sender_addresses' => json_encode($senderStrings),
            'recipients' => json_encode($recipientStrings),
            'recipient_count' => count($recipientStrings),
            'subject' => mb_substr($email->getSubject(), 0, 1000),
            'size_bytes' => $email->getSizeInBytes(),
            'raw_message_base64' => base64_encode($email->toString()),
            'status' => MessageStatus::QUEUED->value,
            'was_queued' => false,
            'attempts' => 0,
            'next_attempt_at' => Time::now(),
            'dispatched_at' => null,
            'created_at' => Time::now(),
            'priority' => Priority::system()->getValue(),
        ];

        self::addMessageToTransaction(ServiceFactory::getTransaction(), $row);
    }


    /**
     * Send the queued email that should go next, if the rate limits and providers allow one: the highest priority
     * first, and oldest first within a priority. Emails are taken oldest first from each token, so a token that has hit
     * its own limit doesn't hold up the others. Run by the queue workers, any number of which can run at once.
     *
     * @return bool - true if an email was sent (or attempted), so the caller knows to check again straight away.
     *
     * @throws ConfigurationException - if settings.yaml isn't valid.
     */
    public static function processNextQueued() : bool
    {
        self::acquireDispatchLock();

        try
        {
            $claimed = self::claimNextEmail();
        }
        finally
        {
            self::releaseDispatchLock();
        }

        if ($claimed['id'] !== '')
        {
            try
            {
                $email = self::loadOutgoingEmail($claimed['id']);
                self::deliver($claimed['id'], $claimed['provider'], $claimed['attempt'], $email);
            }
            catch (InvalidInputException $e)
            {
                // The stored email couldn't be read back. This shouldn't happen, but mustn't stop the queue.
                self::markFailed($claimed['id'], "The stored email couldn't be read: {$e->getMessage()}");
            }
        }

        return $claimed['id'] !== '';
    }


    /**
     * Describe the recipient limits (the token's own, and the global ones) that an email with this many recipients
     * would be over, so could never be sent within. Used by the SMTP server at RCPT TO, before DATA.
     *
     * @param AuthenticatedEmailToken $emailToken - the email token.
     *
     * @param int $recipientCount - how many recipients the email would have.
     *
     * @return string - a description of the limits it would go over, or an empty string if it fits every one.
     */
    public static function describeLimitsExceededByRecipientCount(
        AuthenticatedEmailToken $emailToken,
        int $recipientCount
    ) : string
    {
        $tokenLimits = $emailToken->getPolicy()->getRateLimits();
        return self::describeLimitsThatCanNeverAllow($recipientCount, $tokenLimits, GlobalRateLimitRepository::load());
    }


    /**
     * Put back in the queue any email left "sending" by a process that died part way through. There's a small
     * chance the provider did receive it, in which case it will be sent twice, which is better than never.
     *
     * @return int - how many were put back.
     */
    public static function recoverStuckMessages() : int
    {
        $seconds = self::STUCK_AFTER_SECONDS;

        $cutoff = "unix_time_precise() - {$seconds}";

        $result = ServiceFactory::getDb()->query(
            "UPDATE \"email_message\" SET \"status\" = 'queued', \"next_attempt_at\" = unix_time(),"
            . " \"was_queued\" = true WHERE \"status\" = 'sending' AND \"dispatched_at\" < {$cutoff}"
        );

        // Their attempts stay counted towards the providers' limits, as the providers may have had them.
        ServiceFactory::getDb()->query(
            "UPDATE \"email_attempt\" SET \"outcome\" = 'interrupted', \"finished_at\" = unix_time_precise()"
            . " WHERE \"outcome\" = 'sending' AND \"dispatched_at\" < {$cutoff}"
        );

        return pg_affected_rows($result);
    }


    /**
     * Try to claim a queued email for sending now: check its token's and the global limits have room, and choose a
     * provider that can take it, from those its token may use. Must be called while holding the dispatch lock.
     *
     * @param array $candidate - the email's row: id, email_token_id (null for the service's own emails),
     * recipient_count, tried_providers and sender_addresses.
     *
     * @param ProvidersConfig $config - the providers.
     *
     * @param RateLimitSet $globalLimits - the global limits.
     *
     * @param string[] $reserved - the names of the providers higher priority emails are waiting for room under,
     * which this one mustn't take.
     *
     * @return array - "id" and "provider" of the claimed email and the "attempt" recorded for it, or empty strings
     * if it can't be sent yet. Then "waiting_for_global_limits": whether it's waiting for room under the global limits,
     * and "reserving_providers": the names of the providers it's waiting for room under (none if it's waiting for
     * something else, or none of its providers is enabled).
     */
    private static function tryToClaim(
        array $candidate,
        ProvidersConfig $config,
        RateLimitSet $globalLimits,
        array $reserved
    ) : array
    {
        $claimed = [
            'id' => '',
            'provider' => '',
            'attempt' => '',
            'waiting_for_global_limits' => false,
            'reserving_providers' => [],
        ];

        $isFromService = ($candidate['email_token_id'] === null);

        // The service's own emails have no token, so only the global and per-provider limits apply to them.
        $tokenId = (string)$candidate['email_token_id'];
        $tokenLimits = $isFromService ? new RateLimitSet() : EmailTokenRepository::loadRateLimits($tokenId);
        $tokenScope = $isFromService ? RateLimitScope::global() : RateLimitScope::forEmailToken($tokenId);
        $recipientCount = (int)$candidate['recipient_count'];
        $blockingLimits = self::describeLimitsThatCanNeverAllow($recipientCount, $tokenLimits, $globalLimits);
        $eligibility = self::loadEligibility($candidate['email_token_id'], $candidate['sender_addresses']);

        if ($blockingLimits !== '')
        {
            // The limits were lowered after this email was queued, so it can never be sent. Fail it, rather than let
            // it hold up the token's other emails forever.
            $msg = "It has {$recipientCount} recipients, more than the rate limits now allow: {$blockingLimits}.";
            self::markFailed($candidate['id'], $msg);
        }
        elseif ($eligibility->isRefusedByEveryProvider($config))
        {
            // The providers were restricted to other senders after this email was queued, so none would ever take it.
            // Fail it, as for the limits above.
            $senders = $eligibility->describeSendersNotTaken($config);
            self::markFailed($candidate['id'], "No provider it could use takes its sender ({$senders}) any more.");
        }
        elseif (RateLimiter::allows($tokenLimits, $tokenScope, $recipientCount) === false)
        {
            // Waiting for its own token's limits, which don't hold anything else up.
        }
        elseif (RateLimiter::allows($globalLimits, RateLimitScope::global(), $recipientCount) === false)
        {
            $claimed['waiting_for_global_limits'] = true;
        }
        else
        {
            $triedNames = self::getTriedNames($candidate['tried_providers']);
            $provider = ProviderSelector::choose($config, $eligibility, $recipientCount, $triedNames, $reserved);

            if ($provider !== '')
            {
                $id = ServiceFactory::getDb()->escapeValue($candidate['id']);
                $escapedProvider = ServiceFactory::getDb()->escapeValue($provider);
                $attemptId = PgsqlLib::generateUuid(UuidVersion::V7);
                $escapedAttemptId = ServiceFactory::getDb()->escapeValue($attemptId);

                $markSending =
                    "UPDATE \"email_message\" SET \"status\" = 'sending', \"dispatched_at\" = unix_time_precise(),"
                    . " \"provider\" = {$escapedProvider}, \"attempts\" = \"attempts\" + 1 WHERE \"id\" = {$id}";

                $recordAttempt =
                    'INSERT INTO "email_attempt" ("id", "email_message_id", "provider", "recipient_count", "outcome")'
                    . " VALUES ({$escapedAttemptId}, {$id}, {$escapedProvider}, {$recipientCount}, 'sending')";

                // The attempt is recorded now, so it counts towards the provider's limits straight away.
                $transaction = new OrmTransaction(ServiceFactory::getDb(), [$markSending, $recordAttempt]);
                $transaction->commit();
                $claimed['id'] = $candidate['id'];
                $claimed['provider'] = $provider;
                $claimed['attempt'] = $attemptId;
            }
            else
            {
                // It keeps the providers it's waiting for, so no lower priority email takes their room. With none of
                // its providers enabled (or none left untried in this round), there are none, so it holds nothing up.
                foreach (ProviderSelector::getUsableNow($config, $eligibility, $triedNames) as $usable)
                {
                    /* @var $usable ProviderDefinition */
                    $claimed['reserving_providers'][] = $usable->getName();
                }
            }
        }

        return $claimed;
    }


    /**
     * Get every address an email is sent as (see RawEmail::getSenderAddresses()).
     *
     * @param AuthenticatedEmailToken $emailToken - the token it's being sent with.
     *
     * @param EmailAddress $envelopeSender - its envelope sender.
     *
     * @param RawEmail $email - the email.
     *
     * @return EmailAddressCollection
     *
     * @throws RequestRejectedException - if its From or Sender header can't be read (which PolicyEnforcer's checks
     * would normally have refused already). It's recorded.
     */
    private static function readSenderAddresses(
        AuthenticatedEmailToken $emailToken,
        EmailAddress $envelopeSender,
        RawEmail $email
    ) : EmailAddressCollection
    {
        try
        {
            $senders = $email->getSenderAddresses($envelopeSender);
        }
        catch (InvalidInputException $e)
        {
            $msg = "The From or Sender header is invalid: {$e->getMessage()}";
            $emailToken->reject(RejectionReason::INVALID_MESSAGE, $msg);
        }

        return $senders;
    }


    /**
     * Log an error if no enabled provider takes the service's own email (e.g. a password reset link) from its sender,
     * as it then waits in the queue until one does. The Providers page warns about it too.
     * @param EmailAddressCollection $senders - every address the email is sent as.
     * @return void
     */
    private static function warnIfNoProviderTakesSystemEmail(EmailAddressCollection $senders) : void
    {
        try
        {
            $enabled = ProviderRegistry::getConfig()->getEnabledProviders();
            $eligible = (new ProviderEligibility(new AnyProvider(), $senders))->filter($enabled);

            if (count($eligible) === 0)
            {
                $msg =
                    'No enabled provider takes email from the address the service sends its own emails from (set on'
                    . ' the Settings page), so they wait in the queue until one does.';

                ServiceFactory::getLogger(LogCategory::EMAIL)->error($msg, ['senders' => self::toStrings($senders)]);
            }
        }
        catch (ConfigurationException)
        {
            // There are no providers yet, so it waits for them, as every email does.
        }
    }


    /**
     * Get email addresses as strings, e.g. to store as JSON.
     * @param EmailAddressCollection $addresses - the addresses.
     * @return string[]
     */
    private static function toStrings(EmailAddressCollection $addresses) : array
    {
        $strings = [];

        foreach ($addresses as $address)
        {
            /* @var $address EmailAddress */
            $strings[] = (string)$address;
        }

        return $strings;
    }


    /**
     * Load which providers a queued email can be sent through: those its token may use now, that take its senders.
     *
     * @param string|null $emailTokenId - its token's ID, or null for one of the service's own emails.
     *
     * @param string $senderAddressesJson - its sender_addresses, as stored (a JSON list of addresses).
     *
     * @return ProviderEligibility
     */
    private static function loadEligibility(?string $emailTokenId, string $senderAddressesJson) : ProviderEligibility
    {
        $senders = [];

        foreach (json_decode($senderAddressesJson, true) as $address)
        {
            $senders[] = new EmailAddress($address);
        }

        $allowed = ($emailTokenId === null)
            ? new AnyProvider()
            : EmailTokenRepository::loadProviderRestriction($emailTokenId);

        return new ProviderEligibility($allowed, new EmailAddressCollection(...$senders));
    }


    /**
     * Get the providers reserved by emails with a higher priority than one, which it mustn't take the room under.
     *
     * @param array $reservations - each waiting email's "priority", and the names of the "providers" it's waiting for.
     *
     * @param int $priority - the email's priority.
     *
     * @return string[] - the providers' names.
     */
    private static function getProvidersReservedAbove(array $reservations, int $priority) : array
    {
        $reserved = [];

        foreach ($reservations as $reservation)
        {
            if ($reservation['priority'] > $priority)
            {
                $reserved = array_merge($reserved, $reservation['providers']);
            }
        }

        return array_values(array_unique($reserved));
    }


    /**
     * Hand a claimed email to its provider, and record the result.
     *
     * @param string $messageId - the email's ID.
     *
     * @param string $providerName - the provider chosen for it.
     *
     * @param string $attemptId - the attempt recorded for it (email_attempt), to record how it ended.
     *
     * @param OutgoingEmail $email - the email.
     *
     * @return void
     */
    private static function deliver(
        string $messageId,
        string $providerName,
        string $attemptId,
        OutgoingEmail $email
    ) : void
    {
        try
        {
            $providerMessageId = ProviderRegistry::getProvider($providerName)->send($email);
            self::finishAttempt($attemptId, AttemptOutcome::SENT, '');
            self::markSent($messageId, $providerName, $providerMessageId);
            ProviderStatusRepository::markAvailable($providerName);
        }
        catch (ProviderUnavailableException $e)
        {
            self::finishAttempt($attemptId, AttemptOutcome::UNAVAILABLE, $e->getMessage());
            ProviderStatusRepository::markUnavailable($providerName, $e->getMessage());
            self::tryNextProvider($messageId, $providerName, $e->getMessage(), false);
        }
        catch (ProviderRateLimitedException $e)
        {
            // Over the provider's own rate limit, rather than anything about the email, so in every mode the provider
            // is skipped for a while, and the email goes through another (or waits). The limits here may be too high.
            self::finishAttempt($attemptId, AttemptOutcome::RATE_LIMITED, $e->getMessage());
            ProviderStatusRepository::markRateLimited($providerName, $e->getMessage());
            ProviderRateLimitWarning::log($providerName, $e->getMessage());
            self::tryNextProvider($messageId, $providerName, $e->getMessage(), false);
        }
        catch (ProviderTemporaryFailureException | ConfigurationException | NotFoundException $e)
        {
            // A 4xx reply, a provider whose settings refer to a missing environment variable, or one removed from
            // settings.yaml since the email was claimed: try another.
            self::finishAttempt($attemptId, AttemptOutcome::TEMPORARY_FAILURE, $e->getMessage());
            self::tryNextProvider($messageId, $providerName, $e->getMessage(), false);
        }
        catch (ProviderPermanentFailureException $e)
        {
            self::finishAttempt($attemptId, AttemptOutcome::REFUSED, $e->getMessage());

            if (ProviderRegistry::getConfig()->getMode() === ProviderMode::FALLBACK)
            {
                self::tryNextProvider($messageId, $providerName, $e->getMessage(), true);
            }
            else
            {
                self::markFailed($messageId, "{$providerName}: {$e->getMessage()}");
            }
        }
    }


    /**
     * After a provider failed, put the email straight back in the queue for the next provider, or, if every provider
     * has now been tried in this round, wait for another round (or give up).
     *
     * @param string $messageId - the email's ID.
     *
     * @param string $providerName - the provider that failed.
     *
     * @param string $error - why.
     *
     * @param bool $isPermanent - whether the provider rejected the email outright.
     *
     * @return void
     */
    private static function tryNextProvider(
        string $messageId,
        string $providerName,
        string $error,
        bool $isPermanent
    ) : void
    {
        $id = ServiceFactory::getDb()->escapeValue($messageId);

        $query =
            "SELECT \"tried_providers\", \"retry_round\", \"email_token_id\", \"sender_addresses\""
            . " FROM \"email_message\""
            . " WHERE \"id\" = {$id}";

        $row = pg_fetch_all(ServiceFactory::getDb()->query($query))[0];
        $tried = json_decode($row['tried_providers'], true);
        $tried[] = ['provider' => $providerName, 'permanent' => $isPermanent, 'error' => mb_substr($error, 0, 500)];
        $triedNames = array_column($tried, 'provider');
        $unavailable = ProviderStatusRepository::loadUnavailableNames();
        $remaining = 0;

        // Only the providers its token may use, that take its senders, are left to try.
        $eligibility = self::loadEligibility($row['email_token_id'], $row['sender_addresses']);

        foreach ($eligibility->filter(ProviderRegistry::getConfig()->getEnabledProviders()) as $provider)
        {
            /* @var $provider ProviderDefinition */
            $name = $provider->getName();
            $remaining += (in_array($name, $triedNames, true) || in_array($name, $unavailable, true)) ? 0 : 1;
        }

        $summary = self::summariseAttempts($tried);
        $escapedError = ServiceFactory::getDb()->escapeValue($summary);
        $escapedTried = ServiceFactory::getDb()->escapeValue(json_encode($tried));
        $everyAttemptRejected = (count(array_filter(array_column($tried, 'permanent'))) === count($tried));
        $round = (int)$row['retry_round'] + 1;

        if ($remaining > 0)
        {
            ServiceFactory::getDb()->query(
                "UPDATE \"email_message\" SET \"status\" = 'queued', \"next_attempt_at\" = unix_time(),"
                . " \"tried_providers\" = {$escapedTried}, \"last_error\" = {$escapedError} WHERE \"id\" = {$id}"
            );

            self::wakeWorkers();
        }
        elseif ($everyAttemptRejected)
        {
            self::markFailed($messageId, "Every provider rejected it. {$summary}");
        }
        elseif ($round >= self::MAX_ROUNDS)
        {
            self::markFailed($messageId, "Gave up after {$round} rounds of trying every provider. {$summary}");
        }
        else
        {
            $delay = self::RETRY_DELAYS_SECONDS[min($round, count(self::RETRY_DELAYS_SECONDS)) - 1];

            ServiceFactory::getDb()->query(
                "UPDATE \"email_message\" SET \"status\" = 'queued', \"was_queued\" = true,"
                . " \"retry_round\" = {$round}, \"tried_providers\" = '[]', \"last_error\" = {$escapedError},"
                . " \"next_attempt_at\" = unix_time() + {$delay} WHERE \"id\" = {$id}"
            );
        }
    }


    /**
     * Wait for a worker to try to send an email, and return the outcome. Called by the connection that submitted it.
     *
     * @param string $messageId - the email's ID.
     *
     * @param float $pickupDeadline - when to stop waiting (as a microtime(true)) if no worker has started on it.
     *
     * @return SubmissionOutcome - SENT if it was sent, or QUEUED if it wasn't picked up in time, or will be retried.
     *
     * @throws ProviderPermanentFailureException - if it failed (every provider it could use refused it).
     */
    private static function waitForOutcome(string $messageId, float $pickupDeadline) : SubmissionOutcome
    {
        $id = ServiceFactory::getDb()->escapeValue($messageId);
        $hardDeadline = $pickupDeadline + self::MAX_WAIT_AFTER_PICKUP_SECONDS;
        $outcome = null;

        while ($outcome === null)
        {
            $row = pg_fetch_all(ServiceFactory::getDb()->query(
                'SELECT "status", "last_error", "retry_round", "dispatched_at" IS NOT NULL AS "was_picked_up",'
                . " \"next_attempt_at\" <= unix_time() AS \"is_due\" FROM \"email_message\" WHERE \"id\" = {$id}"
            ))[0];

            $now = microtime(true);
            $wasPickedUp = ($row['was_picked_up'] === 't');
            $isWaitingForRetry = ($wasPickedUp && ($row['is_due'] === 'f' || (int)$row['retry_round'] > 0));

            if ($row['status'] === MessageStatus::SENT->value)
            {
                $outcome = SubmissionOutcome::SENT;
            }
            elseif ($row['status'] === MessageStatus::FAILED->value)
            {
                throw new ProviderPermanentFailureException((string)$row['last_error']);
            }
            elseif (
                   $row['status'] === MessageStatus::CANCELLED->value
                || ($row['status'] === MessageStatus::QUEUED->value && $isWaitingForRetry)
                || ($wasPickedUp === false && $now > $pickupDeadline)
                || $now > $hardDeadline
            )
            {
                $outcome = SubmissionOutcome::QUEUED;
            }
            else
            {
                usleep(self::POLL_MICROSECONDS);
            }
        }

        return $outcome;
    }


    /**
     * Refuse an email that has more recipients than a recipient limit allows, as it could never be sent, however
     * long it waited in the queue.
     *
     * @throws RequestRejectedException - if a limit could never allow it. It's recorded.
     */
    private static function rejectIfLimitsCanNeverAllow(AuthenticatedEmailToken $emailToken, int $recipientCount) : void
    {
        $blockingLimits = self::describeLimitsExceededByRecipientCount($emailToken, $recipientCount);

        if ($blockingLimits !== '')
        {
            $msg =
                "The email has {$recipientCount} recipients, more than the rate limits allow ({$blockingLimits}), so"
                . " it could never be sent. Send it as several emails, each with fewer recipients.";

            $emailToken->reject(RejectionReason::TOO_MANY_RECIPIENTS, $msg);
        }
    }


    /**
     * Describe the limits (a token's own, and the global ones) that an email with this many recipients could never be
     * sent within.
     *
     * @return string - e.g. "the token's limit of 10 recipients per hour", or an empty string if none.
     */
    private static function describeLimitsThatCanNeverAllow(
        int $recipientCount,
        RateLimitSet $tokenLimits,
        RateLimitSet $globalLimits
    ) : string
    {
        $descriptions = [];

        foreach ($tokenLimits->getLimitsThatCanNeverAllow($recipientCount) as $limit)
        {
            $descriptions[] = "the token's limit of {$limit->describe()}";
        }

        foreach ($globalLimits->getLimitsThatCanNeverAllow($recipientCount) as $limit)
        {
            $descriptions[] = "the global limit of {$limit->describe()}";
        }

        return implode(', ', $descriptions);
    }


    /**
     * Record how an attempt at handing an email to a provider ended.
     *
     * @param string $attemptId - the attempt.
     *
     * @param AttemptOutcome $outcome - how it ended.
     *
     * @param string $error - why it failed, or an empty string if it didn't.
     */
    private static function finishAttempt(string $attemptId, AttemptOutcome $outcome, string $error) : void
    {
        $id = ServiceFactory::getDb()->escapeValue($attemptId);
        $escapedOutcome = ServiceFactory::getDb()->escapeValue($outcome->value);
        $escapedError = ($error === '') ? 'NULL' : ServiceFactory::getDb()->escapeValue(mb_substr($error, 0, 1000));

        ServiceFactory::getDb()->query(
            "UPDATE \"email_attempt\" SET \"outcome\" = {$escapedOutcome}, \"finished_at\" = unix_time_precise(),"
            . " \"error\" = {$escapedError} WHERE \"id\" = {$id}"
        );
    }


    private static function markSent(string $messageId, string $providerName, string $providerMessageId) : void
    {
        $id = ServiceFactory::getDb()->escapeValue($messageId);
        $escapedProvider = ServiceFactory::getDb()->escapeValue($providerName);
        $providerId = ServiceFactory::getDb()->escapeValue($providerMessageId);
        $clearContent = self::getClearContentOnFinishSql();

        ServiceFactory::getDb()->query(
            "UPDATE \"email_message\" SET \"status\" = 'sent', \"sent_at\" = unix_time_precise(),"
            . " \"provider\" = {$escapedProvider},"
            . " \"provider_message_id\" = NULLIF({$providerId}, ''), \"last_error\" = NULL{$clearContent}"
            . " WHERE \"id\" = {$id}"
        );

        self::removeAttachmentsOnFinish($messageId);
    }


    private static function markFailed(string $messageId, string $error) : void
    {
        $id = ServiceFactory::getDb()->escapeValue($messageId);
        $escapedError = ServiceFactory::getDb()->escapeValue($error);
        $clearContent = self::getClearContentOnFinishSql();

        ServiceFactory::getDb()->query(
            "UPDATE \"email_message\" SET \"status\" = 'failed', \"last_error\" = {$escapedError}{$clearContent}"
            . " WHERE \"id\" = {$id}"
        );

        self::removeAttachmentsOnFinish($messageId);
    }


    /**
     * Get the assignment that deletes a finishing email's content, if it's not to be kept: when the retention settings
     * say so, and always for the service's own emails, as they hold password reset links, which nobody else should
     * ever be able to read. While the retention settings are invalid, every other email's content is kept.
     */
    private static function getClearContentOnFinishSql() : string
    {
        try
        {
            $clearContent = Settings::get()->getRetention()->getEmailRetention()->getClearContentOnFinishSql();
        }
        catch (ConfigurationException)
        {
            $clearContent = '';
        }

        return ($clearContent !== '')
            ? $clearContent
            : ', "raw_message_base64" = CASE WHEN "email_token_id" IS NULL THEN NULL ELSE "raw_message_base64" END';
    }


    /**
     * Remove a finished email's attachments from its stored content straight away, if its content is kept but its
     * attachments aren't (see the retention settings). While the retention settings are invalid, they're kept.
     */
    private static function removeAttachmentsOnFinish(string $messageId) : void
    {
        try
        {
            $isRemoving = Settings::get()->getRetention()->getEmailRetention()->removesAttachmentsOnFinish();
        }
        catch (ConfigurationException)
        {
            $isRemoving = false;
        }

        if ($isRemoving)
        {
            AttachmentRemover::removeFrom($messageId);
        }
    }


    /**
     * Record that the client was told the email was queued (for the statistics), rather than sent while it waited.
     */
    private static function markAsHavingQueued(string $messageId) : void
    {
        $id = ServiceFactory::getDb()->escapeValue($messageId);
        ServiceFactory::getDb()->query("UPDATE \"email_message\" SET \"was_queued\" = true WHERE \"id\" = {$id}");
    }


    /**
     * Get the names of the providers already tried, from the email's tried_providers column.
     *
     * @return string[]
     */
    private static function getTriedNames(string $triedProvidersJson) : array
    {
        return array_column(json_decode($triedProvidersJson, true), 'provider');
    }


    /**
     * Summarise each provider's attempt in this round, for the email's last_error.
     *
     * @param array $tried - each attempt: "provider", "permanent" and "error".
     *
     * @return string
     */
    private static function summariseAttempts(array $tried) : string
    {
        $parts = [];

        foreach ($tried as $attempt)
        {
            $parts[] = "{$attempt['provider']}: {$attempt['error']}";
        }

        return implode(' | ', $parts);
    }


    /**
     * Rebuild an email from its stored form.
     *
     * @throws InvalidInputException - if it can't be read back.
     */
    private static function loadOutgoingEmail(string $messageId) : OutgoingEmail
    {
        $message = Stats::loadMessage($messageId);
        $recipients = [];

        foreach (json_decode($message->getRecipients(), true) as $recipient)
        {
            $recipients[] = new EmailAddress($recipient);
        }

        $raw = base64_decode((string)$message->getRawMessageBase64(), true);

        if ($raw === false || $raw === '')
        {
            throw new InvalidInputException('The email has no stored content.');
        }

        $sender = new EmailAddress($message->getEnvelopeSender());
        return new OutgoingEmail($messageId, $sender, new EmailAddressCollection(...$recipients), new RawEmail($raw));
    }


    /**
     * Add a new email's row to a transaction, along with waking the queue workers once it has committed.
     *
     * @param OrmTransaction $transaction - the transaction.
     *
     * @param array<string, string|int|bool|null> $row - the email's columns, without its ID or those that start empty.
     *
     * @return string - the email's ID. It doesn't exist until the transaction commits.
     */
    private static function addMessageToTransaction(OrmTransaction $transaction, array $row) : string
    {
        $emptyColumns = [
            'sent_at' => null,
            'provider' => null,
            'provider_message_id' => null,
            'last_error' => null,
            'tried_providers' => '[]',
            'retry_round' => 0,
            'attachments_removed_at' => null,
            'removed_attachment_count' => 0,
        ];

        $message = EmailMessage::createFromArray($row + $emptyColumns);
        $transaction->save($message);
        $transaction->append(self::generateWakeWorkersQuery());
        return (string)$message->getId();
    }


    /**
     * Claim the email that should go next, if the rate limits and providers allow one. The dispatch lock must be held.
     * @return array{id: string, provider: string, attempt: string} - the claim, or empty strings if there isn't one.
     */
    private static function claimNextEmail() : array
    {
        $config = ProviderRegistry::getConfig();
        $globalLimits = GlobalRateLimitRepository::load();
        $claimed = ['id' => '', 'provider' => '', 'attempt' => ''];

        // The oldest due email from each token (and from the service itself, whose emails have no token), highest
        // priority first.
        $candidates = pg_fetch_all(ServiceFactory::getDb()->query(
            'SELECT * FROM (SELECT DISTINCT ON ("email_token_id") "id", "email_token_id", "recipient_count",'
            . ' "tried_providers", "sender_addresses", "priority", "created_at" FROM "email_message"'
            . ' WHERE "status" = \'queued\''
            . ' AND "next_attempt_at" <= unix_time() ORDER BY "email_token_id", "created_at", "id") AS "oldest_per_key"'
            . ' ORDER BY "priority" DESC, "created_at", "id"'
        ));

        // Once an email is held back by the global limits, which every email shares, no lower priority email may use
        // the room it's waiting for. Once one is held back by the providers' limits, no lower priority email may use
        // the room under those providers (the ones it could use), but may still use any others, as an email whose
        // token is restricted to particular providers only waits for those. One held back by its own token's limits
        // doesn't hold anything else up.
        $globalWaitingPriority = 0;
        $reservations = [];

        foreach ($candidates as $candidate)
        {
            $priority = (int)$candidate['priority'];

            if ($claimed['id'] === '' && $priority >= $globalWaitingPriority)
            {
                $reserved = self::getProvidersReservedAbove($reservations, $priority);
                $attempt = self::tryToClaim($candidate, $config, $globalLimits, $reserved);

                if ($attempt['waiting_for_global_limits'])
                {
                    $globalWaitingPriority = max($globalWaitingPriority, $priority);
                }

                if (count($attempt['reserving_providers']) > 0)
                {
                    $reservations[] = ['priority' => $priority, 'providers' => $attempt['reserving_providers']];
                }

                $claimed = [
                    'id' => $attempt['id'],
                    'provider' => $attempt['provider'],
                    'attempt' => $attempt['attempt'],
                ];
            }
        }

        return $claimed;
    }


    /**
     * Wake the queue workers (sent straight away, as this isn't called within a transaction).
     */
    private static function wakeWorkers() : void
    {
        ServiceFactory::getDb()->query(self::generateWakeWorkersQuery());
    }


    /**
     * Generate the query that wakes the queue workers. Inside a transaction, they're only woken once it commits.
     * @return string
     */
    private static function generateWakeWorkersQuery() : string
    {
        return "SELECT pg_notify('" . self::NOTIFY_CHANNEL . "', '')";
    }


    /**
     * Take the dispatch lock, waiting for it if another worker has it. It's held by this connection until it's released
     * (see releaseDispatchLock()), or the connection ends, so a worker that dies can never leave it held.
     */
    private static function acquireDispatchLock() : void
    {
        ServiceFactory::getDb()->query('SELECT pg_advisory_lock(' . self::DISPATCH_LOCK_ID . ')');
    }


    /**
     * Release the dispatch lock. A lost connection has already released it, so that isn't an error here, and any error
     * from the claim that was happening is the useful one.
     */
    private static function releaseDispatchLock() : void
    {
        try
        {
            ServiceFactory::getDb()->query('SELECT pg_advisory_unlock(' . self::DISPATCH_LOCK_ID . ')');
        }
        catch (ExceptionQueryError)
        {
            // The connection has gone, which released the lock.
        }
    }
}
