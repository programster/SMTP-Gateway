<?php

declare(strict_types = 1);


class ViewMessagesPage extends Programster\AbstractView\AbstractView
{
    private readonly EmailMessageCollection $m_messages;
    private readonly EmailTokenCollection $m_emailTokens;
    private readonly MessageFilter $m_filter;


    /**
     * @param EmailMessageCollection $messages - the emails.
     *
     * @param EmailTokenCollection $tokens - every token, for the filter.
     *
     * @param MessageFilter $filter - which emails they are, to show in the filter's fields.
     */
    public function __construct(EmailMessageCollection $messages, EmailTokenCollection $tokens, MessageFilter $filter)
    {
        $this->m_messages = clone $messages;
        $this->m_emailTokens = clone $tokens;
        $this->m_filter = $filter;
    }


    protected function renderContent() : void
    {
        $filter = $this->m_filter;
        $statuses = $filter->getStatuses();
        $status = (count($statuses) > 0) ? $statuses[0]->value : '';
        $events = $filter->getEvents();
        $event = (count($events) > 0) ? $events[0]->value : '';
?>
<h1>Emails</h1>
<form method="get" action="/messages" class="card filters">
    <div>
        <label for="email_token">Email token</label>
        <select id="email_token" name="email_token">
            <option value="">Any email token</option>
            <?php foreach ($this->m_emailTokens as $emailToken): ?>
                <?php /* @var $emailToken EmailToken */ ?>
                <option value="<?= Html::e($emailToken->getId()) ?>"
                    <?= ($emailToken->getId() === $filter->getEmailTokenId()) ? 'selected' : '' ?>><?= Html::e(
                        $emailToken->getName()
                    ) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div>
        <label for="status">Status</label>
        <select id="status" name="status">
            <option value="">Any status</option>
            <?php foreach (MessageStatus::cases() as $case): ?>
                <option value="<?= Html::e($case->value) ?>"
                    <?= ($case->value === $status) ? 'selected' : '' ?>><?= Html::e($case->value) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div>
        <label for="within">When</label>
        <select id="within" name="within">
            <?php foreach (TimeWindow::cases() as $window): ?>
                <option value="<?= Html::e($window->value) ?>"
                    <?= ($window === $filter->getWindow()) ? 'selected' : '' ?>><?= Html::e($window->getLabel())
                    ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div>
        <label for="progress">Progress</label>
        <select id="progress" name="progress">
            <?php foreach (MessageProgress::cases() as $case): ?>
                <option value="<?= $case->value ?>"<?= ($case === $filter->getProgress()) ? ' selected' : '' ?>><?=
                    Html::e($case->getLabel()) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div>
        <label for="event">Reported by the provider</label>
        <select id="event" name="event">
            <option value="">Anything, or nothing</option>
            <?php foreach (EmailEventType::cases() as $case): ?>
                <option value="<?= Html::e($case->value) ?>"
                    <?= ($case->value === $event) ? 'selected' : '' ?>><?= Html::e(str_replace('_', ' ', $case->value))
                    ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div><button type="submit">Filter</button></div>
</form>
<p class="hint">The 200 most recent matching emails. With an event from the provider, "When" is when the event
    happened, rather than when the email was accepted.</p>
<?= new ViewMessagesTable($this->m_messages, $this->m_emailTokens) ?>
<?php
    }
}
