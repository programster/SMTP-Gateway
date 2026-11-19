<?php

/*
 * Removes the attachments from the stored content of emails that have been sent (or have failed, or been cancelled),
 * when the retention settings say they're no longer to be kept, leaving the rest of the content (see
 * AttachmentStripper). Each email is only done once: attachments_removed_at records that it has been, even if it had
 * none.
 *
 * If an email's attachments can't be taken out on their own (e.g. as the whole email is one attachment, or it
 * can't be parsed), its whole content is deleted instead, so attachments are never kept for longer than they should
 * be.
 */

declare(strict_types = 1);


final class AttachmentRemover
{
    // How many emails' IDs are looked up at once. Only the IDs are loaded, and each email's content is loaded on its
    // own (see removeFrom()), so this doesn't change how much memory is used.
    private const int BATCH_SIZE = 20;


    /**
     * Remove the attachments from the emails whose attachments are now due to be removed (see
     * EmailRetentionPolicy::getAttachmentsDueForRemovalSql()).
     *
     * @param EmailRetentionPolicy $policy - how long each part of an email is kept.
     *
     * @return int - how many emails were checked (whether or not they had any attachments).
     */
    public static function removeDue(EmailRetentionPolicy $policy) : int
    {
        $condition = $policy->getAttachmentsDueForRemovalSql();
        $checked = 0;
        $isFinished = ($condition === '');

        while ($isFinished === false)
        {
            $ids = DB::fetchRows(
                "SELECT \"id\" FROM \"email_message\" WHERE {$condition} ORDER BY \"created_at\""
                . ' LIMIT ' . self::BATCH_SIZE
            );

            foreach ($ids as $row)
            {
                self::removeFrom($row['id']);
            }

            $checked += count($ids);
            $isFinished = (count($ids) < self::BATCH_SIZE);
        }

        return $checked;
    }


    /**
     * Remove the attachments from one email's stored content, if it still has its content and hasn't been done
     * already. Used as soon as an email is sent (or fails), when attachments aren't kept at all.
     *
     * @param string $messageId - the email's ID.
     *
     * @return void
     */
    public static function removeFrom(string $messageId) : void
    {
        $db = ServiceFactory::getDb();

        $query =
            'SELECT "raw_message_base64" FROM "email_message" WHERE "id" = $1'
            . ' AND "raw_message_base64" IS NOT NULL AND "attachments_removed_at" IS NULL';

        $result = $db->query($query, [$messageId]);
        $base64 = (pg_num_rows($result) > 0) ? (string)pg_fetch_result($result, 0, 0) : '';

        // The email can be up to SMTP_MAX_MESSAGE_BYTES, so only one copy of it is held at a time where that can be
        // helped: the result and the base64 text are let go as soon as it's decoded, and the content is written back
        // as a query parameter, rather than escaped into the query.
        pg_free_result($result);

        if ($base64 !== '')
        {
            $raw = (string)base64_decode($base64, true);
            unset($base64);

            try
            {
                $stripped = AttachmentStripper::strip($raw);
                unset($raw);
                $removedCount = $stripped->getRemovedCount();

                // Only rewritten when there was something to take out, as the content is otherwise unchanged.
                if ($removedCount > 0)
                {
                    $setSql = '"removed_attachment_count" = $2, "raw_message_base64" = $3';
                    $params = [$messageId, $removedCount, base64_encode($stripped->getRaw())];
                }
                else
                {
                    $setSql = '"removed_attachment_count" = $2';
                    $params = [$messageId, $removedCount];
                }
            }
            catch (\Throwable $e)
            {
                $context = ['email' => $messageId, 'error' => $e->getMessage()];
                $msg = 'Couldn\'t remove the attachments from an email, so deleted its content';
                ServiceFactory::getLogger(LogCategory::SYSTEM)->warning($msg, $context);
                $setSql = '"raw_message_base64" = NULL';
                $params = [$messageId];
            }

            unset($stripped);

            $db->query(
                "UPDATE \"email_message\" SET \"attachments_removed_at\" = now(), {$setSql}"
                . ' WHERE "id" = $1 AND "attachments_removed_at" IS NULL',
                $params
            );
        }
    }
}
