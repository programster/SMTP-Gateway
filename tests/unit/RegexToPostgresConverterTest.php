<?php

/*
 * Converting PHP and JavaScript regular expressions to PostgreSQL's (RegexToPostgresConverter), and refusing anything
 * that has no exact equivalent.
 */

declare(strict_types = 1);

require_once __DIR__ . '/TestSuite.php';

$suite = new TestSuite('Regular expression conversion');
$php = ForeignRegexSyntax::PHP;
$js = ForeignRegexSyntax::JAVASCRIPT;

$converted = [
    ['word boundaries', '\bfailed\B', $php, '\yfailed\Y'],
    ['a backspace in a bracket stays', '[\b]', $php, '[\b]'],
    ['an escaped backslash isn\'t a boundary', '\\\\b', $php, '\\\\b'],
    ['delimiters and flags', '/^failed/i', $php, '^failed'],
    ['other PHP delimiters', '#^failed#i', $php, '^failed'],
    ['JavaScript slashes', '/^failed/gi', $js, '^failed'],
    ['. stops at a line break', 'a.b', $php, 'a[^\n]b'],
    ['. stops at JavaScript\'s line breaks', 'a.b', $js, 'a[^\n\r\u2028\u2029]b'],
    ['. with the s flag', '/a.b/s', $php, 'a.b'],
    ['PHP\'s $ allows a final newline', 'a$', $php, 'a(?=\n?$)'],
    ['JavaScript\'s doesn\'t', 'a$', $js, 'a$'],
    ['nor does PHP\'s with D', '/a$/D', $php, 'a$'],
    ['\z', 'a\z', $php, 'a$'],
    ['named groups and references', '(?<w>a)\k<w>', $php, '(a)(?:\1)'],
    ['(?P=name)', '(?P<w>a)(?P=w)', $php, '(a)(?:\1)'],
    ['a literal brace', 'a{b}', $php, 'a\{b\}'],
    ['a count', 'a{2,3}', $php, 'a{2,3}'],
    ['a lazy quantifier', 'a+?', $js, 'a+?'],
    ['a hex escape can\'t absorb the next digit', '\x41B', $php, '\u0041B'],
    ['a leading (?i)', '(?i)failed', $php, 'failed'],
];

foreach ($converted as [$name, $source, $syntax, $expected])
{
    $actual = RegexToPostgresConverter::convert($source, $syntax);
    $suite->check($name, $actual === $expected, $actual);
}

$refused = [
    ['a possessive quantifier', 'a++', $php],
    ['a possessive count', 'a{2}+', $php],
    ['an atomic group', '(?>a)', $php],
    ['recursion', '(?R)', $php],
    ['a flag partway through', 'a(?i)b', $php],
    ['a Unicode property', '\p{L}', $php],
    ['\R', 'a\R', $php],
    ['the x flag', '/a/x', $php],
    ['JavaScript\'s y flag', '/a/y', $js],
    ['JavaScript\'s empty bracket', '[]', $js],
    ['an unclosed bracket', '[a', $php],
    ['a trailing backslash', 'a\\', $js],
];

foreach ($refused as [$name, $source, $syntax])
{
    $convert = function () use ($source, $syntax) : void {
        RegexToPostgresConverter::convert($source, $syntax);
    };

    $suite->checkThrows("{$name} is refused", InvalidInputException::class, $convert);
}

$suite->finish();
