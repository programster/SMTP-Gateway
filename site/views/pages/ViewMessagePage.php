<?php

/*
 * One email: who sent it, to whom, what happened to it, and what the provider reported afterwards. When its content is
 * kept, it can be read here, with a tab each for its HTML and plain text versions, as well as downloaded.
 */

declare(strict_types = 1);


class ViewMessagePage extends Programster\AbstractView\AbstractView
{
    private readonly EmailMessage $m_message;
    private readonly string $m_emailTokenName;
    private readonly EmailEventCollection $m_events;
    private readonly EmailAttemptCollection $m_attempts;
    private readonly EmailContent $m_content;


    /**
     * @param EmailMessage $message - the email.
     *
     * @param string $emailTokenName - the name of the token it was sent with. Not shown for the service's own emails,
     * which have no token.
     *
     * @param EmailEventCollection $events - what the provider reported about it.
     *
     * @param EmailAttemptCollection $attempts - each time it was handed to a provider.
     *
     * @param EmailContent $content - its HTML and plain text, or empty if its content isn't available (in which case
     * the page says why).
     */
    public function __construct(
        EmailMessage $message,
        string $emailTokenName,
        EmailEventCollection $events,
        EmailAttemptCollection $attempts,
        EmailContent $content
    )
    {
        $this->m_message = $message;
        $this->m_emailTokenName = $emailTokenName;
        $this->m_events = clone $events;
        $this->m_attempts = clone $attempts;
        $this->m_content = $content;
    }


    protected function renderContent() : void
    {
        $message = $this->m_message;
        $settings = Settings::get();
        $removedCount = $message->getRemovedAttachmentCount();

        $removedNote = ($removedCount === 0) ? '' : (
            "Without its {$removedCount} attachment" . (($removedCount === 1) ? '' : 's') . ', removed '
            . Time::format((string)$message->getAttachmentsRemovedAt()) . ', as attachments are '
            . $settings->describeAttachmentRetention() . ' (see Settings).'
        );


        $details = [
            'ID' => $message->getId(),
            'Accepted' => Time::format($message->getCreatedAt()),
            'Sent' => ($message->getSentAt() === null) ? '-' : Time::format($message->getSentAt()),
            'Through' => strtoupper($message->getSource()) . ' from ' . $message->getClientIp(),
            'Envelope sender' => $message->getEnvelopeSender(),
            'Recipients' => implode(', ', json_decode($message->getRecipients(), true)),
            'Subject' => $message->getSubject(),
            'Size' => number_format($message->getSizeBytes()) . ' bytes',
            'Attempts' => (string)$message->getAttempts(),
            'Provider' => trim(($message->getProvider() ?? '-') . ' ' . ($message->getProviderMessageId() ?? '')),
        ];
?>
<h1>Email <span
    class="badge badge-<?= Html::e($message->getStatus()) ?>"><?= Html::e($message->getStatus()) ?></span></h1>
<div class="card">
    <?php if ($message->isFromService()): ?>
        <p>Sent by the service itself (e.g. a password reset link), with the system priority.</p>
    <?php else: ?>
        <?php $tokenPath = '/email-tokens/' . $message->getEmailTokenId(); ?>
        <p>Sent with <a href="<?= Html::e($tokenPath) ?>"><?= Html::e($this->m_emailTokenName) ?></a>, priority
            <?= $message->getPriority() ?><?= $message->wasQueued() ? ', and had to wait in the queue' : '' ?>.</p>
    <?php endif; ?>
    <table>
        <?php foreach ($details as $label => $value): ?>
            <tr><th><?= Html::e($label) ?></th><td><?= Html::e($value) ?></td></tr>
        <?php endforeach; ?>
        <?php if ($message->getLastError() !== null): ?>
            <tr><th>Last error</th><td><?= Html::e($message->getLastError()) ?></td></tr>
        <?php endif; ?>
        <tr>
            <th>Content</th>
            <td>
                <?php if ($message->isFromService()): ?>
                    <span class="muted">Never kept: the service's own emails can hold password reset links.</span>
                <?php elseif ($message->getRawMessageBase64() !== null): ?>
                    <a href="/messages/<?= Html::e($message->getId()) ?>/raw">Download the email (.eml)</a>
                    <?php if ($removedNote !== ''): ?>
                        <br><span class="muted"><?= Html::e($removedNote) ?></span>
                    <?php endif; ?>
                <?php else: ?>
                    <span class="muted">Not available: email content is
                        <?= Html::e($settings->describeContentRetention()) ?> (see Settings).</span>
                <?php endif; ?>
            </td>
        </tr>
    </table>
</div>
<?php if ($this->m_content->hasHtml() || $this->m_content->hasText()): ?>
    <?php $this->renderContentTabs(); ?>
<?php endif; ?>
<h2>Attempts</h2>
<?php if (count($this->m_attempts) === 0): ?>
    <p class="muted">It hasn't been handed to a provider yet.</p>
<?php else: ?>
<div class="table-wrap">
<table>
    <thead><tr><th>Handed over</th><th>Provider</th><th>Outcome</th><th>Answered</th><th>Error</th></tr></thead>
    <tbody>
    <?php foreach ($this->m_attempts as $attempt): ?>
        <?php /* @var $attempt EmailAttempt */ ?>
        <tr>
            <td><?= Html::e(Time::format($attempt->getDispatchedAt())) ?></td>
            <td><?= Html::e($attempt->getProvider()) ?></td>
            <td><?= Html::e($attempt->getOutcome()->getLabel()) ?></td>
            <td><?= Html::e(($attempt->getFinishedAt() === '') ? '-' : Time::format($attempt->getFinishedAt())) ?></td>
            <td><?= Html::e($attempt->getError()) ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
<?php endif; ?>
<h2>Reported by the provider</h2>
<?php if (count($this->m_events) === 0): ?>
    <p class="muted">Nothing yet. Events only appear if the provider's event webhook is set up.</p>
<?php else: ?>
<div class="table-wrap">
<table>
    <thead><tr><th>When</th><th>Event</th><th>Recipient</th><th>Detail</th></tr></thead>
    <tbody>
    <?php foreach ($this->m_events as $event): ?>
        <?php /* @var $event EmailEvent */ ?>
        <tr>
            <td><?= Html::e(Time::format($event->getOccurredAt())) ?></td>
            <td><span
                class="badge badge-<?= Html::e($event->getEventType()) ?>"><?= Html::e(
                    $event->getEventType()
                ) ?></span></td>
            <td><?= Html::e($event->getRecipient() ?? '') ?></td>
            <td><?= Html::e($event->getDetail() ?? '') ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
<?php endif; ?>
<?php
    }


    // The email's HTML and plain text, a tab each, starting on the HTML if it has any.
    private function renderContentTabs() : void
    {
        $content = $this->m_content;
        $isHtmlFirst = $content->hasHtml();
        $htmlPath = '/messages/' . rawurlencode($this->m_message->getId()) . '/html';
?>
<h2>Content</h2>
<div class="card tabs" id="email-content">
    <div class="tab-list" role="tablist" aria-label="The email's content">
        <button type="button" role="tab" id="email-html-tab" aria-controls="email-html"
            aria-selected="<?= $isHtmlFirst ? 'true' : 'false' ?>">HTML</button>
        <button type="button" role="tab" id="email-text-tab" aria-controls="email-text"
            aria-selected="<?= $isHtmlFirst ? 'false' : 'true' ?>">Plain text</button>
    </div>
    <div role="tabpanel" id="email-html" aria-labelledby="email-html-tab"<?= $isHtmlFirst ? '' : ' hidden' ?>>
        <?php if ($content->hasHtml()): ?>
            <iframe class="email-html" src="<?= Html::e($htmlPath) ?>" title="The email's HTML" loading="lazy"
                sandbox="allow-popups allow-popups-to-escape-sandbox" referrerpolicy="no-referrer"></iframe>
            <p class="hint">Remote images aren't loaded, so looking at it here doesn't tell the sender (or the
                provider's open tracking) that it was read. Links open in a new tab.</p>
        <?php else: ?>
            <p class="muted">This email has no HTML version.</p>
        <?php endif; ?>
    </div>
    <div role="tabpanel" id="email-text" aria-labelledby="email-text-tab"<?= $isHtmlFirst ? ' hidden' : '' ?>>
        <?php if ($content->hasText()): ?>
            <pre class="email-text"><?= Html::e($content->getText()) ?></pre>
        <?php else: ?>
            <p class="muted">This email has no plain text version.</p>
        <?php endif; ?>
    </div>
</div>
<script type="module" src="/js/MessagePage.js"></script>
<?php
    }
}
