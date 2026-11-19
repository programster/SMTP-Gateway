<?php

/*
 * How long a part of each email is kept once the email has been sent (or has failed, or been cancelled): its content,
 * or its attachments (see RetentionPeriod and EmailRetentionPolicy). The values are what the Settings page's form
 * sends.
 */

declare(strict_types = 1);


enum RetentionMode : string
{
    // Deleted as soon as the email is sent, fails, or is cancelled.
    case NOT_KEPT = 'not_kept';

    // Kept for as long as what it's part of is: the content for as long as the email's record, and the attachments
    // for as long as the content.
    case KEPT_WITH_PARENT = 'kept_with_parent';

    // Kept for a number of days after the email was sent (or failed, or was cancelled), then deleted.
    case KEPT_FOR_DAYS = 'kept_for_days';
}
