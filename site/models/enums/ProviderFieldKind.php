<?php

/*
 * The kinds of field on the provider form (see ProviderField), which decide how each is shown and read.
 */

declare(strict_types = 1);


enum ProviderFieldKind
{
    // One line of text, e.g. a host or a login.
    case TEXT;

    // A password, key or token, which is never shown, and can be entered or read from an environment variable.
    case SECRET;

    // Several lines of text, e.g. a public key.
    case TEXTAREA;

    // A whole number, e.g. a port.
    case NUMBER;

    // One of a fixed set of values, e.g. a region.
    case CHOICE;
}
