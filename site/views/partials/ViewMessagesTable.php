<?php

/*
 * A table of emails, newest first, linking to each one.
 */

declare(strict_types = 1);


class ViewMessagesTable extends Programster\AbstractView\AbstractView
{
    private readonly EmailMessageCollection $m_messages;
    private readonly EmailTokenCollection $m_emailTokens;


    /**
     * @param EmailMessageCollection $messages - the emails.
     *
     * @param EmailTokenCollection $tokens - the tokens, to show each email's token by name.
     */
    public function __construct(EmailMessageCollection $messages, EmailTokenCollection $tokens)
    {
        $this->m_messages = clone $messages;
        $this->m_emailTokens = clone $tokens;
    }


    protected function renderContent() : void
    {
        $tokenNames = [];

        foreach ($this->m_emailTokens as $emailToken)
        {
            /* @var $emailToken EmailToken */
            $tokenNames[$emailToken->getId()] = $emailToken->getName();
        }
?>
<?php if (count($this->m_messages) === 0): ?>
    <p class="muted">No emails yet.</p>
<?php else: ?>
<div class="table-wrap">
<table>
    <thead><tr><th>Accepted</th><th>Email token</th><th>From</th><th>To</th><th>Subject</th><th>Status</th></tr></thead>
    <tbody>
    <?php foreach ($this->m_messages as $message): ?>
        <?php
            /* @var $message EmailMessage */
            $recipients = json_decode($message->getRecipients(), true);
            $to = $recipients[0] . ((count($recipients) > 1) ? ' +' . (count($recipients) - 1) : '');
        ?>
        <tr>
            <td><a
                href="/messages/<?= Html::e($message->getId()) ?>"><?= Html::e(
                    Time::format($message->getCreatedAt())
                ) ?></a></td>
            <?php if ($message->isFromService()): ?>
                <td><span class="muted">The service</span></td>
            <?php else: ?>
                <?php $emailTokenId = (string)$message->getEmailTokenId(); ?>
                <td><a href="/email-tokens/<?= Html::e($emailTokenId) ?>"><?= Html::e(
                    $tokenNames[$emailTokenId] ?? $emailTokenId
                ) ?></a></td>
            <?php endif; ?>
            <td><?= Html::e($message->getEnvelopeSender()) ?></td>
            <td><?= Html::e($to) ?></td>
            <td><?= Html::e($message->getSubject()) ?></td>
            <td>
                <span
                    class="badge badge-<?= Html::e($message->getStatus()) ?>"><?= Html::e(
                        $message->getStatus()
                    ) ?></span>
                <?php if ($message->wasQueued()): ?><span class="badge"
                    title="Had to wait in the queue">queued</span><?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
<?php endif; ?>
<?php
    }
}
