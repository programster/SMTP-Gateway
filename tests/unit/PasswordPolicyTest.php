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
$isEnough = $isAccepted($default, 'Aaaaaaaaaa1!');
$suite->check('by default, 12 characters with a capital, a number and a symbol are enough', $isEnough);
$suite->check('and 11 aren\'t', !$isAccepted($default, 'Aaaaaaaaa1!'));
$suite->check('nor 12 without a capital letter', !$isAccepted($default, 'aaaaaaaaaa1!'));
$suite->check('nor 12 without a number', !$isAccepted($default, 'Aaaaaaaaaaa!'));
$suite->check('nor 12 without a symbol', !$isAccepted($default, 'Aaaaaaaaaaa1'));
$described = 'At least 12 characters, with a capital letter, a number and a symbol.';
$suite->check('the default is described', $default->describe() === $described, $default->describe());

// settings.yaml's security section: a rule it leaves out is the default's, and one it gives is kept, even if false.
$fromFile = ServiceSettingsParser::parseSecurity(['require_mfa' => true])->getPasswordPolicy();
$isDefault = ($fromFile->describe() === $described);
$suite->check('a security section without the rules has the default ones', $isDefault, $fromFile->describe());
$given = ['require_mfa' => true, 'password_requires_capital' => false, 'password_requires_symbol' => false];
$fromFile = ServiceSettingsParser::parseSecurity($given)->getPasswordPolicy();
$isKept = ($fromFile->requiresCapital() === false && $fromFile->requiresSymbol() === false);
$suite->check('and one that switches a rule off keeps it off', $isKept && $fromFile->requiresNumber());

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

$isAlwaysValid = true;

for ($i = 0; $i < 200; $i++)
{
    $isAlwaysValid = ($isAlwaysValid && $isAccepted($default, PasswordGenerator::generate($default)));
}

$suite->check('a generated password always meets the rules', $isAlwaysValid);
$suite->finish();
