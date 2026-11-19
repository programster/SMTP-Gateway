<?php

/*
 * How a resource variable's value is written in .env.
 */

declare(strict_types = 1);


enum ResourceValueFormat
{
    // A whole number above zero, e.g. "30".
    case COUNT;

    // A number of bytes, e.g. "20971520".
    case BYTES;

    // A size in mebibytes or gibibytes, e.g. "128M" or "2G", which nginx, PHP and Docker all accept.
    case SIZE;

    // A size as PostgreSQL writes it, e.g. "64MB" or "2GB". Only ever written by the app, never read.
    case POSTGRES_SIZE;
}
