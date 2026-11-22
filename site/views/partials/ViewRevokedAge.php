<?php

/*
 * How old a credential was when it was revoked, in days. It's fixed from then on, so unlike ViewKeyAge, it's never
 * highlighted as being due for replacement.
 */

declare(strict_types = 1);


class ViewRevokedAge extends Programster\AbstractView\AbstractView
{
    private readonly int $m_days;


    /**
     * @param int $createdAt - when it was created (a Unix timestamp).
     *
     * @param int $revokedAt - when it was revoked (a Unix timestamp).
     */
    public function __construct(int $createdAt, int $revokedAt)
    {
        $this->m_days = Time::getDaysBetween($createdAt, $revokedAt);
    }


    protected function renderContent() : void
    {
?>
<span class="key-age"><?= number_format($this->m_days) ?> <?= ($this->m_days === 1) ? 'day' : 'days' ?></span>
<?php
    }
}
