<?php

/*
 * What a log entry's context has to have, to be shown: nothing in particular, a name (e.g. "email_token_id"), or a name
 * with a value containing some text (ignoring case), e.g. "email" containing "@example.org". Only the context's top
 * level is looked at, as that's where this service puts what it logs.
 */

declare(strict_types = 1);


final readonly class LogContextMatch
{
    private const string NAME_PATTERN = '/^[A-Za-z0-9_.-]{1,100}$/';

    private string $m_name;
    private string $m_value;


    private function __construct(string $name, string $value)
    {
        $this->m_name = $name;
        $this->m_value = $value;
    }


    public static function createForAny() : LogContextMatch
    {
        return new LogContextMatch('', '');
    }


    /**
     * Create the match from the filter's fields, where either can be empty: both for any context, a name alone for any
     * context with it, or both.
     *
     * @param string $name - the name, e.g. "email".
     *
     * @param string $value - text its value has to contain, or an empty string for any value.
     *
     * @return LogContextMatch
     *
     * @throws InvalidInputException - if there's a value without a name, or the name isn't one this service logs
     * with (letters, digits, "_", "." and "-").
     */
    public static function createFromFields(string $name, string $value) : LogContextMatch
    {
        $name = trim($name);

        if ($name === '' && $value !== '')
        {
            throw new InvalidInputException('Give the name in the context that the value is for, e.g. "email".');
        }

        if ($name !== '' && preg_match(self::NAME_PATTERN, $name) !== 1)
        {
            $msg = "\"{$name}\" isn't a name in the context: they're letters, digits, \"_\", \".\" and \"-\".";
            throw new InvalidInputException($msg);
        }

        return new LogContextMatch($name, $value);
    }


    /**
     * Get the SQL condition for it.
     * @return string - with its values escaped, or "TRUE" for any context.
     */
    public function toSqlCondition() : string
    {
        $name = DB::escape($this->m_name);
        $escapedLike = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $this->m_value);

        return match (true) {
            $this->m_name === '' => 'TRUE',
            $this->m_value === '' => "jsonb_exists(\"context\", {$name})",
            default => "(\"context\"->>{$name}) ILIKE " . DB::escape("%{$escapedLike}%"),
        };
    }


    public function isAny() : bool
    {
        return $this->m_name === '';
    }


    # Accessors
    public function getName() : string { return $this->m_name; }
    public function getValue() : string { return $this->m_value; }
}
