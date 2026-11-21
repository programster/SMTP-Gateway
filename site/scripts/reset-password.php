<?php

/*
 * Resets an account's forgotten password from the command line, for when it can't be done by email (e.g. no provider is
 * working, or PUBLIC_URL and the address the service's own emails are sent from aren't set). Run inside the container:
 *     php /var/www/site/scripts/reset-password.php <email>
 *
 * It sets a new random password and prints it, rather than taking one, so the password never ends up in the shell's
 * history. Change it from "My account" after logging in. The account is logged out everywhere, and its second
 * factor is left as it is (see reset-mfa.php for that).
 */

declare(strict_types = 1);

require_once(__DIR__ . '/../bootstrap.php');


function main(array $argv) : void
{
    if (count($argv) !== 2)
    {
        fwrite(STDERR, "Usage: php reset-password.php <email>" . PHP_EOL);
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

    $password = PasswordGenerator::generate(AdminUserRepository::getPasswordPolicy());
    AdminUserRepository::changePassword($user, $password);
    $logger = ServiceFactory::getLogger(LogCategory::ACCESS);
    $logger->warning('Password was reset from the command line', ['user_id' => $user->getId()]);
    print "The password for \"{$user->getEmail()}\" is now: {$password}" . PHP_EOL;
    print "Change it from \"My account\" once you've logged in." . PHP_EOL;
}


main($argv);
