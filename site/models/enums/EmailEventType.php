<?php

/*
 * The things a provider can report happening to an email after it was handed over.
 */

declare(strict_types = 1);


enum EmailEventType : string
{
    case PROCESSED = 'processed';
    case DELIVERED = 'delivered';
    case DEFERRED = 'deferred';
    case BOUNCED = 'bounced';
    case DROPPED = 'dropped';
    case OPENED = 'opened';
    case CLICKED = 'clicked';
    case SPAM_REPORT = 'spam_report';
    case UNSUBSCRIBED = 'unsubscribed';
    case OTHER = 'other';
}
