<?php

/*
 * Rows of tiles showing how much email has come through, what was rejected, and what the providers reported, for
 * everything (the dashboard) or for one email token (its page). Each tile links to the list of what it counted: the
 * Emails page, or the Rejected requests page, filtered to the same time, status or event (and token, if it's one's).
 */

declare(strict_types = 1);


class ViewActivityCounts extends Programster\AbstractView\AbstractView
{
    private const string NOT_SENT_HELP_ID = 'not-sent-help';

    private const string NOT_SENT_FOOTNOTE =
        'Each email or request is counted once, in one of these. A failed email counts once, however many attempts it'
        . ' took.';

    private readonly ActivityCounts $m_counts;
    private readonly string $m_emailTokenId;


    /**
     * @param ActivityCounts $counts - the counts.
     *
     * @param string $emailTokenId - the email token they're of, which the links filter by too, or an empty string
     * for everything.
     */
    public function __construct(ActivityCounts $counts, string $emailTokenId)
    {
        $this->m_counts = $counts;
        $this->m_emailTokenId = $emailTokenId;
    }


    protected function renderContent() : void
    {
        $counts = $this->m_counts;
        $minute = TimeWindow::LAST_MINUTE->value;
        $hour = TimeWindow::LAST_HOUR->value;
        $day = TimeWindow::LAST_24_HOURS->value;
        $week = TimeWindow::LAST_7_DAYS->value;
        $queued = MessageStatus::QUEUED->value;

        // Each tile: its label, its count, and the list of what it counted.
        $accepted = [
            ['Last minute', $counts->getLastMinute(), $this->getPath('/messages', ['within' => $minute])],
            ['Last hour', $counts->getLastHour(), $this->getPath('/messages', ['within' => $hour])],
            ['Last 24 hours', $counts->getLast24Hours(), $this->getPath('/messages', ['within' => $day])],
            ['Last 7 days', $counts->getLast7Days(), $this->getPath('/messages', ['within' => $week])],
            ['Queued now', $counts->getQueuedNow(), $this->getPath('/messages', ['status' => $queued])],
        ];

        // What didn't go through, each counted once: see createNotSentHelp().
        $byService = ['by' => RejectionOrigin::THIS_SERVICE->value, 'within' => $day];
        $byProvider = ['by' => RejectionOrigin::PROVIDER->value, 'within' => $day];
        $failed = ['progress' => MessageProgress::FAILED_AFTER_QUEUEING->value, 'within' => $day];
        $retrying = ['progress' => MessageProgress::RETRYING->value];

        $notSent = [
            [
                'Rejected by this service',
                $counts->getRejectedByServiceLast24Hours(),
                $this->getPath('/rejections', $byService),
                'Turned away before being accepted',
            ],
            [
                'Refused while waiting',
                $counts->getRefusedWhileWaitingLast24Hours(),
                $this->getPath('/rejections', $byProvider),
                'By a provider, while the client waited',
            ],
            [
                'Failed',
                $counts->getFailedAfterQueueingLast24Hours(),
                $this->getPath('/messages', $failed),
                'Queued, then no provider took it',
            ],
            [
                'Being retried (now)',
                $counts->getRetryingNow(),
                $this->getPath('/messages', $retrying),
                'A provider failed, and it\'s queued again',
            ],
        ];

        $reported = [
            ['Delivered', $counts->getDeliveredLast7Days(), $this->getEventPath(EmailEventType::DELIVERED)],
            ['Bounced', $counts->getBouncedLast7Days(), $this->getEventPath(EmailEventType::BOUNCED)],
            ['Opened', $counts->getOpenedLast7Days(), $this->getEventPath(EmailEventType::OPENED)],
            ['Clicked', $counts->getClickedLast7Days(), $this->getEventPath(EmailEventType::CLICKED)],
            ['Spam reports', $counts->getSpamReportsLast7Days(), $this->getEventPath(EmailEventType::SPAM_REPORT)],
        ];
?>
<h3>Emails accepted</h3>
<?php $this->renderTiles($accepted); ?>
<h3>Not sent (last 24 hours) <?= new ViewHelpButton(self::NOT_SENT_HELP_ID, 'Not sent') ?></h3>
<?php $this->renderTiles($notSent); ?>
<?= new ViewHelpDialog(self::NOT_SENT_HELP_ID, 'Not sent', self::createNotSentHelp(), self::NOT_SENT_FOOTNOTE) ?>
<h3>Reported by the provider (last 7 days)</h3>
<?php $this->renderTiles($reported); ?>
<?php
    }


    /**
     * @param list<array{0: string, 1: int, 2: string, 3?: string}> $tiles - each tile's label, count and link, and
     * optionally a short line saying what it counts.
     */
    private function renderTiles(array $tiles) : void
    {
?>
<div class="tiles">
    <?php foreach ($tiles as $tile): ?>
        <?php [$label, $value, $path] = $tile; ?>
        <a class="tile" href="<?= Html::e($path) ?>"><span class="tile-value"><?= number_format($value) ?></span><span
            class="tile-label"><?= Html::e($label) ?></span><?php if (isset($tile[3])): ?><span
            class="tile-description"><?= Html::e($tile[3]) ?></span><?php endif; ?></a>
    <?php endforeach; ?>
</div>
<?php
    }


    // What each of the "not sent" counts is.
    private static function createNotSentHelp() : HelpEntryCollection
    {
        return new HelpEntryCollection(
            new HelpEntry(
                'Rejected by this service',
                'Requests to send an email that this service turned away itself, before accepting the email or sending'
                . ' anything to a provider: wrong or revoked credentials, an IP address the email token may not be used'
                . ' from, a sender, reply-to or recipient it isn\'t allowed, an email that\'s too large or isn\'t'
                . ' valid, or one with more recipients than a rate limit could ever allow. A run of these from one'
                . ' email token usually means a misconfigured service, or a leaked token.'
            ),
            new HelpEntry(
                'Refused while waiting',
                'Emails a provider refused outright while the client waited to be told what happened (set under'
                . ' Sending, on the General settings page), and that no other provider then took. The client was told'
                . ' its request was rejected, so these are rejected requests too, "refused by the provider".'
            ),
            new HelpEntry(
                'Failed',
                'Emails that were accepted and queued, and then failed: every provider they were tried with refused or'
                . ' failed them, until there were no more tries left. Each email is counted once, however many'
                . ' attempts it took. They\'re on the Emails page, as failed, with each attempt on the email\'s page.'
            ),
            new HelpEntry(
                'Being retried (now)',
                'Emails waiting in the queue after at least one provider failed to take them, to be tried again. They'
                . ' haven\'t failed yet: they may still be sent. This is how many there are now, rather than in the'
                . ' last 24 hours.'
            )
        );
    }


    private function getEventPath(EmailEventType $event) : string
    {
        return $this->getPath('/messages', ['event' => $event->value, 'within' => TimeWindow::LAST_7_DAYS->value]);
    }


    /**
     * Get the path of a list, filtered, and by this email token, if the counts are of one.
     *
     * @param string $path - the list's path, e.g. "/messages".
     *
     * @param array<string, string> $query - its filter, e.g. ['within' => '24h'].
     *
     * @return string
     */
    private function getPath(string $path, array $query) : string
    {
        $withToken = ($this->m_emailTokenId === '') ? $query : ['email_token' => $this->m_emailTokenId] + $query;
        return $path . '?' . http_build_query($withToken);
    }
}
