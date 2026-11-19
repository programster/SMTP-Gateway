<?php

/*
 * The outcome of checking whether a domain's SPF record lets an address send as it (RFC 7208, section 2.6), plus
 * UNKNOWN for a record SpfEvaluator can't work through on its own.
 */

declare(strict_types = 1);


enum SpfResult : string
{
    case PASS = 'pass';
    case FAIL = 'fail';
    case SOFTFAIL = 'softfail';
    case NEUTRAL = 'neutral';
    case NONE = 'none';
    case PERMERROR = 'permerror';
    case TEMPERROR = 'temperror';

    // The record uses a macro (e.g. "exists:%{i}._spf.example.org"), which depends on the email being sent.
    case UNKNOWN = 'unknown';


    /**
     * Get the result a qualifier gives when its mechanism matches, e.g. "-" for "-all".
     *
     * @param string $qualifier - "+", "-", "~", "?", or an empty string (which means "+").
     *
     * @return SpfResult
     */
    public static function createFromQualifier(string $qualifier) : SpfResult
    {
        return match ($qualifier) {
            '-' => SpfResult::FAIL,
            '~' => SpfResult::SOFTFAIL,
            '?' => SpfResult::NEUTRAL,
            default => SpfResult::PASS,
        };
    }
}
