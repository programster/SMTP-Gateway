<?php

/*
 * Creates the default web interface account from ADMIN_EMAIL and ADMIN_PASSWORD, if there's no account for that
 * email address yet. An existing account is never changed, so a password changed (or two-factor authentication
 * set up) in the web interface isn't undone by restarting. Run on every start up, after the migrations.
 */

declare(strict_types = 1);

require_once(__DIR__ . '/../bootstrap.php');


function main() : void
{
    $email = Config::getEnv('ADMIN_EMAIL');
    $password = getenv('ADMIN_PASSWORD');

    if ($email === '' || $password === false || $password === '')
    {
        fwrite(STDERR, 'ADMIN_EMAIL and ADMIN_PASSWORD must be set.' . PHP_EOL);
        exit(1);
    }

    try
    {
        $address = new EmailAddress($email);

        if (AdminUserRepository::isEmailTaken($address))
        {
            print "The account for {$email} already exists, so it has been left as it is." . PHP_EOL;
        }
        else
        {
            AdminUserRepository::createWithPassword($address, $password, false);
            print "Created the account for {$email}." . PHP_EOL;
        }
    }
    catch (InvalidInputException $e)
    {
        $msg = "Couldn't create the default account (ADMIN_EMAIL, ADMIN_PASSWORD): {$e->getMessage()}";
        fwrite(STDERR, $msg . PHP_EOL);
        exit(1);
    }
}


main();
