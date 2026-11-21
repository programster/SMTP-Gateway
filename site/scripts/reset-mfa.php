<?php

/*
 * Removes every second factor (the authenticator app and all security keys) from an account, and its backup codes,
 * for when someone has lost them and can't log in. Run inside the container:
 *     php /var/www/site/scripts/reset-mfa.php <email>
 */

declare(strict_types = 1);

require_once(__DIR__ . '/../bootstrap.php');


function main(array $argv) : void
{
    if (count($argv) !== 2)
    {
        fwrite(STDERR, "Usage: php reset-mfa.php <email>" . PHP_EOL);
        exit(1);
    }

    try
    {
        $user = AdminUserRepository::loadByEmail($argv[1]);
    }
    catch (NotFoundException $e)
    {
        fwrite(STDERR, $e->getMessage() . PHP_EOL);
        exit(1);
    }

    TotpService::disable($user);

    foreach (SecurityKeyService::loadCredentials($user) as $credential)
    {
        $credential->delete();
    }

    BackupCodeService::deleteAll($user);
    MfaGraceService::cancel($user);

    $logger = ServiceFactory::getLogger(LogCategory::ACCESS);

    $logger->warning('Two-factor authentication was reset from the command line', [
        'user_id' => $user->getId(),
    ]);

    print "Removed every second factor, and the backup codes, from \"{$user->getEmail()}\"." . PHP_EOL;

    if (Settings::get()->isMfaRequired())
    {
        print "As a second factor is required, they'll be asked to set up a new one when they next log in." . PHP_EOL;
    }
}


main($argv);
