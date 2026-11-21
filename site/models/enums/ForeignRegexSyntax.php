<?php

/*
 * The kinds of regular expression that have to be converted before PostgreSQL can run them (see
 * RegexToPostgresConverter).
 */

declare(strict_types = 1);


enum ForeignRegexSyntax : string
{
    case PHP = 'php';
    case JAVASCRIPT = 'javascript';


    public function getLabel() : string
    {
        return match ($this) {
            ForeignRegexSyntax::PHP => 'PHP',
            ForeignRegexSyntax::JAVASCRIPT => 'JavaScript',
        };
    }


    /**
     * Get the characters that can wrap a pattern, as in /pattern/i.
     * @return string[]
     */
    public function getDelimiters() : array
    {
        return match ($this) {
            ForeignRegexSyntax::PHP => ['/', '#', '~', '%', '@', '!'],
            ForeignRegexSyntax::JAVASCRIPT => ['/'],
        };
    }


    /**
     * Get the flags that mean the same once converted. Case is always ignored, so "i" changes nothing.
     * @return string - each flag, as one character.
     */
    public function getSupportedFlags() : string
    {
        return match ($this) {
            ForeignRegexSyntax::PHP => 'imsuD',
            ForeignRegexSyntax::JAVASCRIPT => 'dgimsu',
        };
    }


    /**
     * Get what counts as a line break, for "." and multiline "^" and "$", as a PostgreSQL bracket's contents.
     * @return string
     */
    public function getLineBreaks() : string
    {
        return match ($this) {
            ForeignRegexSyntax::PHP => '\n',
            ForeignRegexSyntax::JAVASCRIPT => '\n\r\u2028\u2029',
        };
    }
}
