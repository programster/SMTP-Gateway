<?php

/*
 * The rules a password must meet (PasswordPolicy): its length, and, when they're set, a capital letter, a number and a
 * symbol, saying everything a password is missing, and describing the rules for the forms.
 */

declare(strict_types = 1);

require_once __DIR__ . '/TestSuite.php';

$suite = new TestSuite('Password rules');

$isAccepted = function (PasswordPolicy $policy, string $password) : bool {
    $isAccepted = true;

    try
    {
        $policy->check($password);
    }
    catch (InvalidInputException)
    {
        $isAccepted = false;
    }

    return $isAccepted;
};

$default = PasswordPolicy::createDefault();
$suite->check('by default, 12 characters of any kind are enough', $isAccepted($default, 'aaaaaaaaaaaa'));
$suite->check('and 11 aren\'t', !$isAccepted($default, 'aaaaaaaaaaa'));
$suite->check('the default is described', $default->describe() === 'At least 12 characters.', $default->describe());

$strict = new PasswordPolicy(10, true, true, true);
$suite->check('every rule met is accepted', $isAccepted($strict, 'Abcdefgh1!'));
$suite->check('a capital letter is needed', !$isAccepted($strict, 'abcdefgh1!'));
$suite->check('a number is needed', !$isAccepted($strict, 'Abcdefghi!'));
$suite->check('a symbol is needed', !$isAccepted($strict, 'Abcdefgh12'));
$suite->check('a space counts as a symbol', $isAccepted($strict, 'Abc defgh1'));
$suite->check('as do letters from other alphabets\' capitals', $isAccepted($strict, 'Ωbcdefgh1!'));

$message = '';

try
{
    $strict->check('abc');
}
catch (InvalidInputException $e)
{
    $message = $e->getMessage();
}

$expected = 'The password needs at least 10 characters, a capital letter, a number and a symbol.';
$suite->check('a password missing everything is told all of it', $message === $expected, $message);
$described = $strict->describe();
$isDescribed = ($described === 'At least 10 characters, with a capital letter, a number and a symbol.');
$suite->check('and the rules are described', $isDescribed, $described);

$tooShort = function () : void {
    new PasswordPolicy(PasswordPolicy::LOWEST_MIN_LENGTH - 1, false, false, false);
};

$tooLong = function () : void {
    new PasswordPolicy(PasswordPolicy::HIGHEST_MIN_LENGTH + 1, false, false, false);
};

$suite->checkThrows('a minimum below 8 is refused', InvalidInputException::class, $tooShort);
$suite->checkThrows('as is one over 64, past what bcrypt uses', InvalidInputException::class, $tooLong);

$suite->finish();
