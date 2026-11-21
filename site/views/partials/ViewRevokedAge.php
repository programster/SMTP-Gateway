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
     * @param string $createdAt - when it was created, as read from the database.
     *
     * @param string $revokedAt - when it was revoked, as read from the database.
     */
    public function __construct(string $createdAt, string $revokedAt)
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
