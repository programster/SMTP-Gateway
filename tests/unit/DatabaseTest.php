<?php

/*
 * The database connection (one per process, from ServiceFactory, and never open while forking), and replacing the
 * global rate limits.
 */

declare(strict_types = 1);

require_once __DIR__ . '/TestSuite.php';

$suite = new TestSuite('Database');

/**
 * Get this process's database session's ID.
 */
$getSessionId = function () : int {
    return (int)DB::fetchValue('SELECT pg_backend_pid()');
};

// Forking first, as a process must never fork with a connection open (see ServiceFactory::getDb()).
$suite->check('not connected until asked', ServiceFactory::isDbConnected() === false);
$childPid = pcntl_fork();

if ($childPid === 0)
{
    exit((DB::fetchValue('SELECT 1') === '1') ? 0 : 1);
}

pcntl_waitpid($childPid, $status);
$suite->check('a forked child connects for itself', pcntl_wexitstatus($status) === 0);
$suite->check('without connecting its parent', ServiceFactory::isDbConnected() === false);

$connection = ServiceFactory::getDb();
$suite->check('the same connection every time', ServiceFactory::getDb() === $connection);
$tables = [EmailTokenTable::getInstance(), EmailMessageTable::getInstance(), AdminUserTable::getInstance()];
$isShared = true;

foreach ($tables as $table)
{
    $isShared = $isShared && ($table->getDb() === $connection);
}

$suite->check('every table uses it', $isShared);
$sessionId = $getSessionId();

for ($i = 0; $i < 50; $i++)
{
    ServiceFactory::getDb()->query('SELECT 1');
}

$suite->check('the same database session after 50 uses', $getSessionId() === $sessionId);

// The SMTP server forks for each connection, so it refuses to while connected to the database.
[$serverEnd, $clientEnd] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);

$handleConnection = function () use ($serverEnd) : void {
    $method = new ReflectionMethod(SmtpServer::class, 'handleConnection');
    $method->invoke(new SmtpServer(), $serverEnd, '127.0.0.1:5000', false);
};

$suite->checkThrows('the SMTP server won\'t fork while connected', LogicException::class, $handleConnection);
$told = trim((string)fgets($clientEnd));
$suite->check('and tells the client it can\'t take the connection', str_starts_with($told, '4'), $told);

// Replacing the global rate limits.
$perSecond = new RateLimit(RateLimitUnit::EMAILS, 10, 1);
$limits = new RateLimitSet($perSecond, new RateLimit(RateLimitUnit::EMAILS, 300, 3600));
GlobalRateLimitRepository::replace($limits);
GlobalRateLimitRepository::replace($limits);
$described = GlobalRateLimitRepository::load()->describe();
$isOneCopy = substr_count($described, '300 emails') === 1;
$suite->check('replacing the global limits twice keeps one copy', $isOneCopy, $described);
GlobalRateLimitRepository::replace(new RateLimitSet());
$suite->check('they can be cleared', GlobalRateLimitRepository::load()->isEmpty());

$suite->finish();
