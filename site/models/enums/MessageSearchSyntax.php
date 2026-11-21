<?php

/*
 * How the text in the Logs page's "Message contains" box is read: as plain text, or as one kind of regular expression.
 */

declare(strict_types = 1);


enum MessageSearchSyntax : string
{
    case PLAIN_TEXT = '';
    case POSTGRESQL = 'postgresql';
    case PHP = 'php';
    case JAVASCRIPT = 'javascript';


    public function isRegex() : bool
    {
        return ($this !== MessageSearchSyntax::PLAIN_TEXT);
    }


    public function getLabel() : string
    {
        return match ($this) {
            MessageSearchSyntax::PLAIN_TEXT => 'Plain text',
            MessageSearchSyntax::POSTGRESQL => 'PostgreSQL',
            MessageSearchSyntax::PHP => 'PHP',
            MessageSearchSyntax::JAVASCRIPT => 'JavaScript',
        };
    }


    /**
     * Get the pattern to give PostgreSQL for what was typed, converting it if it's another kind of regular expression.
     *
     * @param string $search - what was typed.
     *
     * @return string
     *
     * @throws InvalidInputException - if it uses something that has no PostgreSQL equivalent.
     */
    public function toPostgresPattern(string $search) : string
    {
        $javascript = ForeignRegexSyntax::JAVASCRIPT;

        return match ($this) {
            MessageSearchSyntax::PLAIN_TEXT, MessageSearchSyntax::POSTGRESQL => $search,
            MessageSearchSyntax::PHP => RegexToPostgresConverter::convert($search, ForeignRegexSyntax::PHP),
            MessageSearchSyntax::JAVASCRIPT => RegexToPostgresConverter::convert($search, $javascript),
        };
    }
}
