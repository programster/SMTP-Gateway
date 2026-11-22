<?php

/*
 * When something was last used (an account's last login, or an API key's or email token's last use), for a table's
 * cell: how long ago, in days (or, if it was less than a day ago, e.g. "3 hours ago"), then the time itself, and the IP
 * address it was used from. "Never" if it hasn't been. The IP address is left out if it was last used before they were
 * recorded.
 */

declare(strict_types = 1);


class ViewLastUse extends Programster\AbstractView\AbstractView
{
    private readonly ?int $m_usedAt;
    private readonly string $m_ip;


    /**
     * @param int|null $usedAt - when it was last used (a Unix timestamp), or null if it never has been.
     *
     * @param string|null $ip - the IP address it was used from, or null if that isn't known.
     */
    public function __construct(?int $usedAt, ?string $ip)
    {
        $this->m_usedAt = $usedAt;
        $this->m_ip = (string)$ip;
    }


    protected function renderContent() : void
    {
?>
<?php if ($this->m_usedAt === null): ?>
    <span class="muted">Never</span>
<?php else: ?>
    <?= Html::e(Time::describeDaysAgo($this->m_usedAt)) ?>
    <br><span class="hint"><?= Html::e(Time::format($this->m_usedAt)) ?><?php if ($this->m_ip !== ''): ?>, from
        <span class="mono"><?= Html::e($this->m_ip) ?></span><?php endif; ?></span>
<?php endif; ?>
<?php
    }
}
