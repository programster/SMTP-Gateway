<?php

/*
 * A password hash (PasswordHash), as the default account's is given in ADMIN_PASSWORD_HASH: only a hash that
 * password_verify() can check against is accepted, base64 encoded or not, so a plain password can never be stored as
 * one, and its errors never repeat the value given.
 */

declare(strict_types = 1);

require_once __DIR__ . '/TestSuite.php';

$suite = new TestSuite('Password hashes');
$password = 'correct-horse-battery';
$bcrypt = password_hash($password, PASSWORD_BCRYPT);
$argon = password_hash($password, PASSWORD_ARGON2ID);

$getError = function (Closure $code) : string {
    $message = '';

    try
    {
        $code();
    }
    catch (InvalidInputException $e)
    {
        $message = $e->getMessage();
    }

    return $message;
};

$fromBcrypt = new PasswordHash($bcrypt);
$suite->check('a bcrypt hash is accepted as it is', $fromBcrypt->getHash() === $bcrypt);
$suite->check('an argon2id one is too', (new PasswordHash($argon))->getHash() === $argon);

$fromBase64 = PasswordHash::createFromBase64(base64_encode($bcrypt));
$suite->check('a base64 encoded hash is decoded', $fromBase64->getHash() === $bcrypt);
$suite->check('which the password matches', password_verify($password, $fromBase64->getHash()));

$withSpace = PasswordHash::createFromBase64(' ' . base64_encode($bcrypt) . "\n");
$suite->check('surrounding whitespace is ignored', $withSpace->getHash() === $bcrypt);

$plain = function () use ($password) : void {
    new PasswordHash($password);
};

$plainEncoded = function () use ($password) : void {
    PasswordHash::createFromBase64(base64_encode($password));
};

$notEncoded = function () use ($bcrypt) : void {
    PasswordHash::createFromBase64($bcrypt);
};

$plainNotEncoded = function () : void {
    PasswordHash::createFromBase64('my password!');
};

$truncated = function () use ($bcrypt) : void {
    new PasswordHash(substr($bcrypt, 0, 40));
};

$empty = function () : void {
    PasswordHash::createFromBase64('');
};

$suite->checkThrows('a plain password is refused', InvalidInputException::class, $plain);
$suite->checkThrows('as is a base64 encoded one', InvalidInputException::class, $plainEncoded);
$suite->checkThrows('and a hash that isn\'t base64 encoded', InvalidInputException::class, $notEncoded);
$suite->checkThrows('and a plain password that isn\'t base64', InvalidInputException::class, $plainNotEncoded);
$suite->checkThrows('and a cut off hash', InvalidInputException::class, $truncated);
$suite->checkThrows('and nothing at all', InvalidInputException::class, $empty);

$plainError = $getError($plainEncoded);
$suite->check('the error doesn\'t repeat the password', !str_contains($plainError, $password), $plainError);
$notBase64Error = $getError($plainNotEncoded);
$isNotRepeated = !str_contains($notBase64Error, 'my password!');
$suite->check('even when it isn\'t base64', $isNotRepeated, $notBase64Error);

$suite->finish();
