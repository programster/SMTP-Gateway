<?php

/*
 * A log entry's level, as programster/pgsql-logger stores it ("log_level", in this order), from the least severe to
 * the most.
 */

declare(strict_types = 1);


enum LogLevel : string
{
    case DEBUG = 'debug';
    case INFO = 'info';
    case NOTICE = 'notice';
    case WARNING = 'warning';
    case ERROR = 'error';
    case CRITICAL = 'critical';
    case ALERT = 'alert';
    case EMERGENCY = 'emergency';


    public function getLabel() : string
    {
        return ucfirst($this->value);
    }
}
