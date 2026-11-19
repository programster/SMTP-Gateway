<?php

/*
 * Removes the attachments from the stored content of the emails whose attachments are no longer to be kept (as the
 * retention settings say), leaving the rest of their content. Run every five minutes from cron. Emails that
 * are sent, or fail, have theirs removed straight away when attachments aren't kept at all, so this picks up those
 * kept for a number of days, and those cancelled by revoking their email token.
 */

declare(strict_types = 1);

require_once(__DIR__ . '/../bootstrap.php');


function main() : void
{
    try
    {
        $retention = Settings::get()->getRetention()->getEmailRetention();
        $checked = AttachmentRemover::removeDue($retention);

        if ($checked > 0)
        {
            $kept = $retention->describeAttachments();
            $message = "Checked {$checked} emails for attachments to remove (attachments are {$kept}).";
            print date('c') . " {$message}" . PHP_EOL;
        }
    }
    catch (ConfigurationException $e)
    {
        // Nothing is removed while the retention settings are invalid, so nothing meant to be kept is lost.
        fwrite(STDERR, date('c') . " No attachments were removed: {$e->getMessage()}" . PHP_EOL);
    }
}


main();
