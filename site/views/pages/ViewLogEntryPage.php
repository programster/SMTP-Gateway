<?php

/*
 * One log entry in full: when it was logged, its level, its message, and its context: each name and value, which
 * links to the logs filtered by it, and the whole of it as indented JSON. An account's ID (see LogContextKey) also links
 * to the account, showing what it is now: its email address, an archived account, or a deleted one.
 */

declare(strict_types = 1);


class ViewLogEntryPage extends Programster\AbstractView\AbstractView
{
    private readonly LogEntry $m_entry;
    private readonly DateTimeZone $m_timezone;

    /** @var array<string, string> */
    private readonly array $m_accounts;


    /**
     * @param LogEntry $entry - the entry.
     *
     * @param DateTimeZone $timezone - the time zone its time is shown in.
     *
     * @param array<string, string> $accounts - what each account ID in its context is now (see
     * AdminUser::describe()), by ID, or an empty string for one that's been deleted.
     */
    public function __construct(LogEntry $entry, DateTimeZone $timezone, array $accounts)
    {
        $this->m_accounts = $accounts;
        $this->m_entry = $entry;
        $this->m_timezone = $timezone;
    }


    protected function renderContent() : void
    {
        $entry = $this->m_entry;
        $context = $entry->getPrettyContext();
?>
<p><a href="/admin/logs">Back to the logs</a></p>
<h1>Log entry</h1>
<div class="card">
    <dl class="details">
        <dt>Time</dt>
        <dd><?= Html::e(Time::formatUnixTimestamp($entry->getCreatedAt())) ?> <span
            class="hint">(<?= Html::e($this->m_timezone->getName()) ?>, <?= Html::e(
                Time::describeAgo((float)$entry->getCreatedAt())
            ) ?>)</span></dd>
        <dt>Level</dt>
        <dd><span class="badge badge-<?= Html::e($entry->getLevel()) ?>"><?= Html::e($entry->getLevel()) ?></span></dd>
        <dt>Message</dt>
        <dd><?= Html::e($entry->getMessage()) ?></dd>
        <dt>ID</dt>
        <dd class="mono"><?= Html::e($entry->getId()) ?></dd>
    </dl>
</div>
<h2>Context</h2>
<?php if ($context === ''): ?>
    <p class="muted">None.</p>
<?php else: ?>
    <p class="hint">Click a value to see every log entry with it.</p>
    <div class="table-wrap">
    <table>
        <thead><tr><th>Name</th><th>Value</th></tr></thead>
        <tbody>
        <?php foreach ($entry->getContextValues() as $name => $value): ?>
            <?php $query = http_build_query(['context_name' => $name, 'context_value' => $value]); ?>
            <tr>
                <td class="mono"><?= Html::e($name) ?></td>
                <td><a class="mono" href="/admin/logs?<?= Html::e($query) ?>"><?= Html::e($value) ?></a><?php
                    if (isset($this->m_accounts[$value])): ?><?php $this->renderAccount($value); ?><?php endif;
                    ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <details class="card advanced">
        <summary>As JSON</summary>
        <pre class="log-context"><?= Html::e($context) ?></pre>
    </details>
<?php endif; ?>
<?php
    }


    // What an account named by its ID is now, linking to it if it still exists.
    private function renderAccount(string $userId) : void
    {
        $description = $this->m_accounts[$userId];
?>
<br><?php if ($description === ''): ?><span class="muted">Deleted account</span><?php else: ?><a
    href="/admin/users/<?= Html::e(rawurlencode($userId)) ?>"><?= Html::e($description) ?></a><?php endif; ?>
<?php
    }
}
