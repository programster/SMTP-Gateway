<?php

declare(strict_types = 1);


class ViewRejectionsPage extends Programster\AbstractView\AbstractView
{
    private readonly RejectedRequestCollection $m_rejections;
    private readonly EmailTokenCollection $m_emailTokens;
    private readonly string $m_emailTokenId;
    private readonly TimeWindow $m_window;
    private readonly RejectionOrigin $m_origin;


    /**
     * @param RejectedRequestCollection $rejections - the rejected requests.
     *
     * @param EmailTokenCollection $tokens - every token, for their names, and the filter.
     *
     * @param string $emailTokenId - the token being filtered by, or an empty string.
     *
     * @param TimeWindow $window - how far back they go.
     *
     * @param RejectionOrigin $origin - who turned them away.
     */
    public function __construct(
        RejectedRequestCollection $rejections,
        EmailTokenCollection $tokens,
        string $emailTokenId,
        TimeWindow $window,
        RejectionOrigin $origin
    )
    {
        $this->m_rejections = clone $rejections;
        $this->m_emailTokens = clone $tokens;
        $this->m_emailTokenId = $emailTokenId;
        $this->m_window = $window;
        $this->m_origin = $origin;
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
<h1>Rejected requests</h1>
<p>Requests to send email that this service turned away, before anything was sent to a provider: wrong credentials, a
    revoked email token, an IP address or email address the token isn't allowed to use, and so on. A run of these from
    one token usually means a misconfigured service, or a leaked token. The one exception is "refused by the provider":
    the client waited to be told what happened, and the provider refused the email outright in that time. An email a
    provider refuses after it was queued is on the <a href="/messages?status=failed">Emails</a> page instead, as
    failed.</p>
<form method="get" action="/rejections" class="card filters">
    <div>
        <label for="email_token">Email token</label>
        <select id="email_token" name="email_token">
            <option value="">Any (or none)</option>
            <?php foreach ($this->m_emailTokens as $emailToken): ?>
                <option value="<?= Html::e($emailToken->getId()) ?>"
                    <?= ($emailToken->getId() === $this->m_emailTokenId) ? 'selected' : '' ?>><?= Html::e(
                        $emailToken->getName()
                    ) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div>
        <label for="within">When</label>
        <select id="within" name="within">
            <?php foreach (TimeWindow::cases() as $window): ?>
                <option value="<?= Html::e($window->value) ?>"
                    <?= ($window === $this->m_window) ? 'selected' : '' ?>><?= Html::e($window->getLabel()) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div>
        <label for="by">Rejected by</label>
        <select id="by" name="by">
            <?php foreach (RejectionOrigin::cases() as $origin): ?>
                <?php $selected = ($origin === $this->m_origin) ? ' selected' : ''; ?>
                <option value="<?= $origin->value ?>"<?= $selected ?>><?= Html::e($origin->getLabel()) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div><button type="submit">Filter</button></div>
</form>
<p class="hint">The 200 most recent matching requests.</p>
<?php if (count($this->m_rejections) === 0): ?>
    <p class="muted">None.</p>
<?php else: ?>
<div class="table-wrap">
<table>
    <thead><tr><th>When</th><th>Email token</th><th>From</th><th>Reason</th><th>Detail</th></tr></thead>
    <tbody>
    <?php foreach ($this->m_rejections as $rejection): ?>
        <?php
        /* @var $rejection RejectedRequest */
        $emailTokenId = $rejection->getEmailTokenId();
        $tokenName = Html::e($tokenNames[(string)$emailTokenId] ?? (string)$emailTokenId);
        ?>
        <tr>
            <td><?= Html::e(Time::format($rejection->getCreatedAt())) ?></td>
            <td><?= ($emailTokenId === null)
                ? '<span class="muted">unknown</span>'
                : '<a href="/email-tokens/' . Html::e($emailTokenId) . '">' . $tokenName . '</a>' ?></td>
            <td><?= Html::e(strtoupper($rejection->getSource()) . ' ' . $rejection->getClientIp()) ?></td>
            <td><span class="badge badge-failed"><?= Html::e($rejection->getReason()) ?></span></td>
            <td><?= Html::e($rejection->getDetail()) ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
<?php endif; ?>
<?php
    }
}
