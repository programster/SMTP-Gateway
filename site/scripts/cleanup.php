<?php

/*
 * Deletes what's past its retention period (set on the Settings page), run daily from cron:
 *  - the records of emails (with their tracking events) and rejected requests older than email records are kept,
 *  - logs older than they're kept,
 *  - emails' content once its time has passed, and
 *  - emails' attachments once their time has passed (scripts/remove-attachments.php does this every five minutes
 *    too).
 * While the retention settings are invalid, nothing is deleted.
 */

declare(strict_types = 1);

require_once(__DIR__ . '/../bootstrap.php');


function main() : void
{
    try
    {
        $settings = Settings::get()->getRetention();
        cleanUp($settings->getEmailRetention(), $settings->getLogDays());
    }
    catch (ConfigurationException $e)
    {
        // Nothing is deleted while the retention settings are invalid, so nothing meant to be kept is lost.
        fwrite(STDERR, date('c') . " Nothing was deleted: {$e->getMessage()}" . PHP_EOL);
    }
}


/**
 * Delete everything that's past its time.
 *
 * @param EmailRetentionPolicy $retention - how long each part of an email is kept.
 *
 * @param int $logDays - how many days the logs are kept.
 *
 * @return void
 */
function cleanUp(EmailRetentionPolicy $retention, int $logDays) : void
{
    $recordDays = $retention->getRecordDays();
    $recordCutoff = "unix_time() - {$recordDays} * 86400";
    $logCutoff = "unix_time() - {$logDays} * 86400";
    $db = ServiceFactory::getDb();

    $finished = "\"status\" NOT IN ('queued', 'sending')";

    $queries = [
        "emails older than {$recordDays} days" =>
            "DELETE FROM \"email_message\" WHERE \"created_at\" < {$recordCutoff} AND {$finished}",
        "rejected requests older than {$recordDays} days" =>
            "DELETE FROM \"rejected_request\" WHERE \"created_at\" < {$recordCutoff}",
        "logs older than {$logDays} days" =>
            "DELETE FROM \"log\" WHERE \"created_at\" < {$logCutoff}",
        'failed login counts whose window ended over a day ago' =>
            'DELETE FROM "login_throttle" WHERE "window_started_at" < unix_time() - 86400',
    ];

    foreach ($queries as $description => $query)
    {
        $deleted = pg_affected_rows($db->query($query));
        print date('c') . " Deleted {$deleted} {$description}." . PHP_EOL;
    }

    // The content of the emails that are left, if it's no longer to be kept.
    $contentCondition = $retention->getContentDueForDeletionSql();

    if ($contentCondition !== '')
    {
        $query = "UPDATE \"email_message\" SET \"raw_message_base64\" = NULL WHERE {$contentCondition}";
        $cleared = pg_affected_rows($db->query($query));
        $kept = $retention->describeContent();
        print date('c') . " Deleted the content of {$cleared} emails (content is {$kept})." . PHP_EOL;
    }

    // Then the attachments of those whose content is still kept.
    $checked = AttachmentRemover::removeDue($retention);
    $kept = $retention->describeAttachments();
    print date('c') . " Checked {$checked} emails for attachments to remove (attachments are {$kept})." . PHP_EOL;
}


main();
