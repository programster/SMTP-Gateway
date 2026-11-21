<?php

/*
 * Converts a PHP (PCRE) or JavaScript regular expression into a PostgreSQL one that matches the same text, e.g. \b
 * becomes \y. It reads the pattern a piece at a time, as what a character means depends on where it is (\b is a word
 * boundary, but a backspace inside [...]). Anything with no exact equivalent, such as a possessive quantifier, is
 * refused, rather than quietly searching for something slightly different. It's only for finding whether text
 * matches, so differences in which part of the text a match covers don't matter.
 */

declare(strict_types = 1);


final class RegexToPostgresConverter
{
    private readonly string $m_pattern;
    private readonly ForeignRegexSyntax $m_syntax;
    private readonly bool $m_isDotAll;
    private readonly bool $m_isMultiline;
    private readonly bool $m_isDollarEndOnly;
    private int $m_position = 0;
    private string $m_output = '';
    private bool $m_isAfterQuantifier = false;
    private int $m_groupCount = 0;

    /** @var array<string, int> each named group's number */
    private array $m_groupNames = [];


    /**
     * @param string $source - the regular expression, with or without delimiters and flags.
     *
     * @param ForeignRegexSyntax $syntax - what kind it is.
     *
     * @throws InvalidInputException - if it has a flag that can't be converted.
     */
    private function __construct(string $source, ForeignRegexSyntax $syntax)
    {
        [$pattern, $flags] = self::splitDelimiters($source, $syntax);

        foreach (str_split($flags) as $flag)
        {
            if (!str_contains($syntax->getSupportedFlags(), $flag))
            {
                $label = $syntax->getLabel();
                throw new InvalidInputException("The {$label} flag \"{$flag}\" has no PostgreSQL equivalent.");
            }
        }

        $this->m_pattern = $pattern;
        $this->m_syntax = $syntax;
        $this->m_isDotAll = str_contains($flags, 's');
        $this->m_isMultiline = str_contains($flags, 'm');
        $this->m_isDollarEndOnly = ($syntax === ForeignRegexSyntax::JAVASCRIPT || str_contains($flags, 'D'));
    }


    /**
     * Convert a regular expression to PostgreSQL's syntax.
     *
     * @param string $source - the regular expression, with or without delimiters and flags, e.g. /^failed\b/i.
     *
     * @param ForeignRegexSyntax $syntax - what kind it is.
     *
     * @return string - the PostgreSQL regular expression.
     *
     * @throws InvalidInputException - if it uses something that has no PostgreSQL equivalent.
     */
    public static function convert(string $source, ForeignRegexSyntax $syntax) : string
    {
        $converter = new self($source, $syntax);
        $converter->scan();
        return $converter->m_output;
    }


    /**
     * Split a regular expression from its delimiters and flags, if it has them.
     *
     * @param string $source - the regular expression.
     *
     * @param ForeignRegexSyntax $syntax - what kind it is.
     *
     * @return string[] - the pattern, and its flags.
     */
    private static function splitDelimiters(string $source, ForeignRegexSyntax $syntax) : array
    {
        $first = substr($source, 0, 1);
        $parts = [$source, ''];

        if (in_array($first, $syntax->getDelimiters(), true))
        {
            $end = strrpos($source, $first);

            if ($end > 0 && preg_match('/^[a-zA-Z]*$/D', substr($source, $end + 1)) === 1)
            {
                $parts = [substr($source, 1, $end - 1), substr($source, $end + 1)];
            }
        }

        return $parts;
    }


    private function scan() : void
    {
        if ($this->m_pattern !== '' && str_contains('*+?', $this->m_pattern[0]))
        {
            throw new InvalidInputException("That isn't a valid regular expression (nothing before the quantifier).");
        }

        while ($this->m_position < strlen($this->m_pattern))
        {
            $this->scanPiece();
        }
    }


    private function scanPiece() : void
    {
        $char = $this->m_pattern[$this->m_position];
        $wasAfterQuantifier = $this->m_isAfterQuantifier;
        $this->m_isAfterQuantifier = false;

        switch ($char)
        {
            case '\\':
                $this->scanEscape();
                break;

            case '[':
                $this->scanBracket();
                break;

            case '(':
                $this->scanGroupStart();
                break;

            case '.':
                $this->emit($this->m_isDotAll ? '.' : "[^{$this->m_syntax->getLineBreaks()}]", 1);
                break;

            case '^':
                $this->emit($this->getStartOfLinePattern(), 1);
                break;

            case '$':
                $this->emit($this->getEndOfTextPattern(), 1);
                break;

            case '*':
            case '+':
            case '?':
                $this->scanQuantifier($char, $wasAfterQuantifier);
                break;

            case '{':
                $this->scanBrace();
                break;

            case '}':
                $this->emit('\}', 1);
                break;

            default:
                $this->emit($char, 1);
        }
    }


    // What "^" means: in multiline mode, PHP's doesn't match after a newline that's the very last character.
    private function getStartOfLinePattern() : string
    {
        $lineBreaks = $this->m_syntax->getLineBreaks();
        $notAtEnd = ($this->m_syntax === ForeignRegexSyntax::PHP) ? '(?!$)' : '';
        return $this->m_isMultiline ? "(?:^|(?<=[{$lineBreaks}]){$notAtEnd})" : '^';
    }


    // What "$" means: PHP's also matches just before a final newline, unless it has the D flag.
    private function getEndOfTextPattern() : string
    {
        $lineBreaks = $this->m_syntax->getLineBreaks();

        return match (true) {
            $this->m_isMultiline => "(?=[{$lineBreaks}]|$)",
            $this->m_isDollarEndOnly => '$',
            default => '(?=\n?$)',
        };
    }


    private function scanQuantifier(string $char, bool $wasAfterQuantifier) : void
    {
        if ($wasAfterQuantifier && $char === '+')
        {
            $this->refuse('a possessive quantifier (such as a++)');
        }

        $this->emit($char, 1);
        $this->m_isAfterQuantifier = ($wasAfterQuantifier === false);
    }


    // A brace that isn't a valid count, like {a}, is an ordinary character in PHP and JavaScript.
    private function scanBrace() : void
    {
        if (preg_match('/\G\{\d+(?:,\d*)?\}/', $this->m_pattern, $matches, 0, $this->m_position) === 1)
        {
            $this->emit($matches[0], strlen($matches[0]));
            $this->m_isAfterQuantifier = true;
        }
        else
        {
            $this->emit('\{', 1);
        }
    }


    private function scanEscape() : void
    {
        $next = $this->getEscapedCharacter();
        $isPhp = ($this->m_syntax === ForeignRegexSyntax::PHP);

        if (ctype_alnum($next) === false)
        {
            $this->emit("\\{$next}", 2);
        }
        elseif ($next >= '1' && $next <= '9')
        {
            preg_match('/\G\\\\\d+/', $this->m_pattern, $matches, 0, $this->m_position);
            $this->emit($matches[0], strlen($matches[0]));
        }
        elseif (str_contains('dDsSwWnrtfv', $next))
        {
            $this->emit("\\{$next}", 2);
        }
        elseif ($next === 'b')
        {
            $this->emit('\y', 2);
        }
        elseif ($next === 'B')
        {
            $this->emit('\Y', 2);
        }
        elseif ($next === 'x' || $next === 'u')
        {
            $this->scanCodePoint();
        }
        elseif ($next === 'k')
        {
            $this->scanNamedReference();
        }
        elseif ($isPhp && $next === 'A')
        {
            $this->emit('\A', 2);
        }
        elseif ($isPhp && $next === 'z')
        {
            $this->emit('$', 2);
        }
        elseif ($isPhp && $next === 'Z')
        {
            $this->emit('(?=\n?$)', 2);
        }
        else
        {
            $this->refuse("\\{$next}");
        }
    }


    /**
     * Get the character after the backslash at the current position.
     * @throws InvalidInputException - if the pattern ends there.
     */
    private function getEscapedCharacter() : string
    {
        if ($this->m_position + 1 >= strlen($this->m_pattern))
        {
            throw new InvalidInputException("That isn't a valid regular expression (it ends with a backslash).");
        }

        return $this->m_pattern[$this->m_position + 1];
    }


    // A character by its code, e.g. \x41 or \u{263A}, as PostgreSQL's \x reads as many hex digits as there are.
    private function scanCodePoint() : void
    {
        $regex = ($this->m_syntax === ForeignRegexSyntax::PHP)
            ? '/\G\\\\x(?:\{([0-9a-fA-F]+)\}|([0-9a-fA-F]{1,2}))/'
            : '/\G\\\\(?:x([0-9a-fA-F]{2})|u\{([0-9a-fA-F]+)\}|u([0-9a-fA-F]{4}))/';

        if (preg_match($regex, $this->m_pattern, $matches, 0, $this->m_position) !== 1)
        {
            $this->refuse(substr($this->m_pattern, $this->m_position, 2));
        }

        $codePoint = hexdec((string)end($matches));

        if ($codePoint > 0x10FFFF)
        {
            $this->refuse($matches[0]);
        }

        $escape = ($codePoint <= 0xFFFF) ? sprintf('\u%04X', $codePoint) : sprintf('\U%08X', $codePoint);
        $this->emit($escape, strlen($matches[0]));
    }


    private function scanNamedReference() : void
    {
        $regex = ($this->m_syntax === ForeignRegexSyntax::PHP)
            ? '/\G\\\\k(?:<(\w+)>|\{(\w+)\}|\'(\w+)\')/'
            : '/\G\\\\k<([\w$]+)>/';

        if (preg_match($regex, $this->m_pattern, $matches, 0, $this->m_position) !== 1)
        {
            $this->refuse('\k');
        }

        $this->emitNamedReference((string)end($matches), strlen($matches[0]));
    }


    /**
     * Refer back to a named group by its number, in a group of its own, so a digit after it isn't read as part of it.
     *
     * @param string $name - the group's name.
     *
     * @param int $length - how much of the pattern the reference takes up.
     *
     * @return void
     *
     * @throws InvalidInputException - if no group of that name comes before it.
     */
    private function emitNamedReference(string $name, int $length) : void
    {
        if (!isset($this->m_groupNames[$name]))
        {
            $message = "The regular expression refers to a group \"{$name}\" before it's defined.";
            throw new InvalidInputException($message);
        }

        $this->emit("(?:\\{$this->m_groupNames[$name]})", $length);
    }


    private function scanGroupStart() : void
    {
        $rest = substr($this->m_pattern, $this->m_position);
        $isPhp = ($this->m_syntax === ForeignRegexSyntax::PHP);
        $nameRegex = $isPhp ? '/^\(\?(?:P?<([A-Za-z_]\w*)>|\'([A-Za-z_]\w*)\')/' : '/^\(\?<([A-Za-z_$][\w$]*)>/';

        if (preg_match($nameRegex, $rest, $matches) === 1)
        {
            $name = ($matches[1] !== '') ? $matches[1] : $matches[2];
            $this->m_groupCount++;
            $this->m_groupNames[$name] = $this->m_groupCount;
            $this->emit('(', strlen($matches[0]));
        }
        elseif (preg_match('/^\(\?(?::|=|!|<=|<!)/', $rest, $matches) === 1)
        {
            $this->emit($matches[0], strlen($matches[0]));
        }
        elseif ($isPhp && preg_match('/^\(\?P=(\w+)\)/', $rest, $matches) === 1)
        {
            $this->emitNamedReference($matches[1], strlen($matches[0]));
        }
        elseif ($isPhp && $this->m_position === 0 && str_starts_with($rest, '(?i)'))
        {
            $this->emit('', 4);
        }
        elseif (str_starts_with($rest, '(?') || str_starts_with($rest, '(*'))
        {
            $this->refuse(substr($rest, 0, 3));
        }
        else
        {
            $this->m_groupCount++;
            $this->emit('(', 1);
        }
    }


    private function scanBracket() : void
    {
        $isPhp = ($this->m_syntax === ForeignRegexSyntax::PHP);
        $this->emit('[', 1);

        if ($this->peek() === '^')
        {
            $this->emit('^', 1);
        }

        if ($this->peek() === ']' && $isPhp)
        {
            $this->emit('\]', 1);
        }
        elseif ($this->peek() === ']')
        {
            $this->refuse('an empty [] or [^]');
        }

        $isOpen = true;

        while ($isOpen)
        {
            $char = $this->peek();

            if ($char === '')
            {
                throw new InvalidInputException("That isn't a valid regular expression (a [ is never closed).");
            }
            elseif ($char === ']')
            {
                $this->emit(']', 1);
                $isOpen = false;
            }
            elseif ($char === '\\')
            {
                $this->scanBracketEscape();
            }
            elseif ($char === '[')
            {
                $this->scanBracketOpen($isPhp);
            }
            else
            {
                $this->emit($char, 1);
            }
        }
    }


    // Inside [...], \b is a backspace in all three, so it stays as it is.
    private function scanBracketEscape() : void
    {
        $next = $this->getEscapedCharacter();

        if (ctype_alnum($next) === false || str_contains('dDsSwWnrtfvb', $next))
        {
            $this->emit("\\{$next}", 2);
        }
        elseif ($next === 'x' || $next === 'u')
        {
            $this->scanCodePoint();
        }
        else
        {
            $this->refuse("\\{$next}");
        }
    }


    // A [ inside [...] is an ordinary character, unless it starts one of PHP's classes, like [:alpha:].
    private function scanBracketOpen(bool $isPhp) : void
    {
        $regex = '/\G\[:\^?[a-z]+:\]/';

        if ($isPhp && preg_match($regex, $this->m_pattern, $matches, 0, $this->m_position) === 1)
        {
            if (str_contains($matches[0], '^'))
            {
                $this->refuse($matches[0]);
            }

            $this->emit($matches[0], strlen($matches[0]));
        }
        else
        {
            $this->emit('\[', 1);
        }
    }


    private function peek() : string
    {
        return $this->m_pattern[$this->m_position] ?? '';
    }


    private function emit(string $text, int $consumed) : void
    {
        $this->m_output .= $text;
        $this->m_position += $consumed;
    }


    /**
     * @throws InvalidInputException - always, naming what can't be converted.
     */
    private function refuse(string $part) : never
    {
        $label = $this->m_syntax->getLabel();
        $message = "This {$label} regular expression uses {$part}, which PostgreSQL has no equivalent for.";
        throw new InvalidInputException($message);
    }
}
