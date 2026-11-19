<?php

/*
 * How a new account gets its password: its owner chooses it, with a link emailed to them, or an administrator sets it
 * (and, usually, has it changed when they first log in).
 */

declare(strict_types = 1);


enum AccountPasswordSource : string
{
    case EMAILED_LINK = 'link';
    case SET_NOW = 'set';
}
