<?php

/*
 * The form backup codes take (BackupCode): generated codes are ten characters in two groups of five, with no
 * characters that are easily mixed up, and codes are read back however they're typed.
 */

declare(strict_types = 1);

require_once __DIR__ . '/TestSuite.php';

$suite = new TestSuite('Backup codes');

$code = BackupCode::generate();
$suite->check('a code is two groups of five', preg_match('/^[a-z2-9]{5}-[a-z2-9]{5}$/', $code) === 1, $code);
$normalised = BackupCode::normalise($code);
$suite->check('which normalises to ten characters', strlen($normalised) === BackupCode::LENGTH, $normalised);
$suite->check('in the form a code takes', BackupCode::isValid($normalised), $normalised);

$generated = [];

for ($i = 0; $i < 200; $i++)
{
    $generated[] = BackupCode::generate();
}

$all = implode('', $generated);
$suite->check('no easily mixed up characters are used', preg_match('/[01ilo]/', $all) === 0, $all);
$suite->check('codes are random', count(array_unique($generated)) === count($generated));

$typed = BackupCode::normalise(' K7M2P  X9Q4R ');
$suite->check('a typed code is read in any case, with spaces', $typed === 'k7m2px9q4r', $typed);
$typed = BackupCode::normalise('k7m2p-x9q4r');
$suite->check('or with the dash', $typed === 'k7m2px9q4r', $typed);
$suite->check('a code that\'s too short isn\'t valid', BackupCode::isValid('k7m2px9q4') === false);
$suite->check('nor one that\'s too long', BackupCode::isValid('k7m2px9q4rr') === false);
$suite->check('nor one with a character codes never have', BackupCode::isValid('k7m2px9q40') === false);
$suite->check('nor a TOTP code', BackupCode::isValid(BackupCode::normalise('123 456')) === false);

$suite->finish();
