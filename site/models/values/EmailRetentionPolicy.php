<?php

/*
 * How long each part of an email is kept, from three settings (in settings.yaml's retention section, set on the
 * Settings page), each of which fits within the one before it:
 *  - email_record_days: its record (who it was from and to, its subject, and what happened to it), a number
 *    of days. Its content goes with it.
 *  - email_content_days: its content, once it's been sent: -1 (not kept), 0 (as long as the record), or a
 *    number of days, no more than the record's. Its attachments go with it.
 *  - email_attachment_days: its attachments (every part other than the plain text and HTML body, inline
 *    images included): -1 (not kept), 0 (as long as the content), or a number of days, no more than the content's.
 *
 * Until it's sent, an email is always kept whole, as it's needed to send it.
 */

declare(strict_types = 1);


readonly class EmailRetentionPolicy
{
    // When an email finished: when it was sent, or for one that failed or was cancelled, when it was accepted.
    private const string FINISHED_AT_SQL = 'coalesce("sent_at", "created_at")';

    private const string FINISHED_WITH_CONTENT_SQL =
        "\"status\" IN ('sent', 'failed', 'cancelled') AND \"raw_message_base64\" IS NOT NULL";

    private int $m_recordDays;
    private RetentionPeriod $m_content;
    private RetentionPeriod $m_attachments;


    /**
     * @param int $recordDays - how many days each email's record is kept.
     *
     * @param RetentionPeriod $content - how long its content is kept.
     *
     * @param RetentionPeriod $attachments - how long its attachments are kept.
     *
     * @throws InvalidInputException - if $recordDays isn't above zero, or the content would be kept for longer than
     * the record, or the attachments for longer than the content.
     */
    public function __construct(int $recordDays, RetentionPeriod $content, RetentionPeriod $attachments)
    {
        $contentDays = $content->getEffectiveDays($recordDays);

        if ($recordDays < 1)
        {
            throw new InvalidInputException("Email records must be kept for at least 1 day, not {$recordDays}.");
        }
        elseif ($contentDays > $recordDays)
        {
            $msg =
                "Email content can't be kept for longer ({$contentDays} days) than email records ({$recordDays}"
                . " days), as an email's content is deleted with its record. Choose \"as long as the record\" to keep"
                . ' the content for as long as the record.';

            throw new InvalidInputException($msg);
        }
        elseif ($contentDays === -1 && $attachments->getMode() === RetentionMode::KEPT_FOR_DAYS)
        {
            $msg =
                "Attachments can't be kept ({$attachments->getDays()} days) when email content isn't kept, as an"
                . ' email\'s attachments are deleted with its content.';

            throw new InvalidInputException($msg);
        }
        elseif ($attachments->getEffectiveDays($contentDays) > $contentDays)
        {
            $msg =
                "Attachments can't be kept for longer ({$attachments->getDays()} days) than the {$contentDays} days"
                . " email content is kept for, as an email's attachments are deleted with its content. Choose \"as"
                . ' long as the content" to keep the attachments for as long as the content.';

            throw new InvalidInputException($msg);
        }

        $this->m_recordDays = $recordDays;
        $this->m_content = $content;
        $this->m_attachments = $attachments;
    }


    /**
     * Read the policy from the settings.
     *
     * @param string $recordDays - how many days email records are kept.
     *
     * @param string $contentDays - how long email content is kept: -1 (not kept), 0 (as long as the record), or a
     * number of days.
     *
     * @param string $attachmentDays - how long attachments are kept: -1 (not kept), 0 (as long as the content), or a
     * number of days.
     *
     * @return EmailRetentionPolicy
     *
     * @throws InvalidInputException - if a setting is invalid, or they don't fit within each other. The message says
     * which.
     */
    public static function createFromSettings(
        string $recordDays,
        string $contentDays,
        string $attachmentDays
    ) : EmailRetentionPolicy
    {
        if (preg_match('/^[1-9][0-9]{0,4}$/', trim($recordDays)) !== 1)
        {
            $msg = "How long email records are kept must be a whole number of days, not \"{$recordDays}\".";
            throw new InvalidInputException($msg);
        }

        $content = self::readPeriod('How long email content is kept', $contentDays);
        $attachments = self::readPeriod('How long attachments are kept', $attachmentDays);
        return new EmailRetentionPolicy((int)trim($recordDays), $content, $attachments);
    }


    public function isContentKept() : bool
    {
        return $this->getContentDays() !== -1;
    }


    /**
     * Whether an email's attachments are to be removed from its content as soon as it's sent (or fails, or is
     * cancelled): when its content is kept, but its attachments aren't.
     *
     * @return bool
     */
    public function removesAttachmentsOnFinish() : bool
    {
        return $this->isContentKept() && $this->m_attachments->getMode() === RetentionMode::NOT_KEPT;
    }


    /**
     * Get how many days an email's content is kept once it's been sent.
     *
     * @return int - the number of days, or -1 if it isn't kept.
     */
    public function getContentDays() : int
    {
        return $this->m_content->getEffectiveDays($this->m_recordDays);
    }


    /**
     * Get how many days an email's attachments are kept once it's been sent.
     *
     * @return int - the number of days, or -1 if they aren't kept.
     */
    public function getAttachmentDays() : int
    {
        $contentDays = $this->getContentDays();
        return ($contentDays === -1) ? -1 : $this->m_attachments->getEffectiveDays($contentDays);
    }


    /**
     * Get the assignment to add to the UPDATE that marks an email as finished (sent, failed or cancelled), which
     * deletes its content straight away if it isn't kept.
     *
     * @return string - e.g. ', "raw_message_base64" = NULL', or an empty string if the content is kept.
     */
    public function getClearContentOnFinishSql() : string
    {
        return $this->isContentKept() ? '' : ', "raw_message_base64" = NULL';
    }


    /**
     * Get the SQL condition picking out the finished emails whose content should be deleted now. That's every
     * finished email when the content isn't kept (in case it was kept before), and those that finished more than the
     * number of days ago when it's kept for a number of days. When it's kept with the record, it goes when the record
     * is deleted.
     *
     * @return string - the condition, or an empty string if no content is due to be deleted.
     */
    public function getContentDueForDeletionSql() : string
    {
        $cutoff = "now() - make_interval(days => {$this->getContentDays()})";
        $older = self::FINISHED_WITH_CONTENT_SQL . ' AND ' . self::FINISHED_AT_SQL . " < {$cutoff}";

        return match ($this->m_content->getMode()) {
            RetentionMode::NOT_KEPT => self::FINISHED_WITH_CONTENT_SQL,
            RetentionMode::KEPT_WITH_PARENT => '',
            RetentionMode::KEPT_FOR_DAYS => $older,
        };
    }


    /**
     * Get the SQL condition picking out the finished emails whose attachments should be removed now, from those
     * whose content is kept and still has them. That's every one when attachments aren't kept (in case they were
     * kept before), and those that finished more than the number of days ago when they're kept for a number of days.
     * When they're kept with the content, they go when the content is deleted.
     *
     * @return string - the condition, or an empty string if no attachments are due to be removed.
     */
    public function getAttachmentsDueForRemovalSql() : string
    {
        $due = self::FINISHED_WITH_CONTENT_SQL . ' AND "attachments_removed_at" IS NULL';
        $cutoff = "now() - make_interval(days => {$this->getAttachmentDays()})";
        $older = $due . ' AND ' . self::FINISHED_AT_SQL . " < {$cutoff}";

        $condition = match ($this->m_attachments->getMode()) {
            RetentionMode::NOT_KEPT => $due,
            RetentionMode::KEPT_WITH_PARENT => '',
            RetentionMode::KEPT_FOR_DAYS => $older,
        };

        return $this->isContentKept() ? $condition : '';
    }


    /**
     * Describe how long an email's content is kept, e.g. "kept for 7 days after it has been sent".
     *
     * @return string
     */
    public function describeContent() : string
    {
        $days = self::describeDays($this->getContentDays());

        return match ($this->m_content->getMode()) {
            RetentionMode::NOT_KEPT => 'deleted as soon as it has been sent',
            RetentionMode::KEPT_WITH_PARENT => "kept for as long as its record is ({$this->m_recordDays} days)",
            RetentionMode::KEPT_FOR_DAYS => "kept for {$days} after it has been sent",
        };
    }


    /**
     * Describe how long an email's attachments are kept, e.g. "removed 7 days after it has been sent".
     *
     * @return string
     */
    public function describeAttachments() : string
    {
        $attachmentDays = $this->getAttachmentDays();

        return match (true) {
            $this->isContentKept() === false => 'deleted with the rest of its content, as soon as it has been sent',
            $attachmentDays === -1 => 'removed as soon as it has been sent',
            $attachmentDays === $this->getContentDays() => 'kept for as long as the rest of its content is',
            default => 'removed ' . self::describeDays($attachmentDays) . ' after it has been sent',
        };
    }


    /**
     * @throws InvalidInputException - if the setting isn't -1, 0, or a whole number of days.
     */
    private static function readPeriod(string $name, string $setting) : RetentionPeriod
    {
        try
        {
            $period = RetentionPeriod::createFromSetting($setting);
        }
        catch (InvalidInputException $e)
        {
            throw new InvalidInputException("{$name} is invalid. {$e->getMessage()}");
        }

        return $period;
    }


    private static function describeDays(int $days) : string
    {
        return $days . ' day' . (($days === 1) ? '' : 's');
    }


    # Accessors
    public function getRecordDays() : int { return $this->m_recordDays; }
    public function getContentSetting() : int { return $this->m_content->toSetting(); }
    public function getAttachmentSetting() : int { return $this->m_attachments->toSetting(); }
}
