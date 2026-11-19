<?php

/*
 * A dashboard widget listing what was used most recently, and from where: e.g. the last few logins, or the API keys
 * last used. Its title links to the page for managing them all, and each name to its own page.
 */

declare(strict_types = 1);


class ViewRecentUseCard extends Programster\AbstractView\AbstractView
{
    private readonly string $m_title;
    private readonly string $m_path;
    private readonly string $m_emptyText;
    private readonly RecentUseCollection $m_uses;


    /**
     * @param string $title - what they are, e.g. "Recent logins".
     *
     * @param string $path - the page for managing them all, e.g. "/admin/users".
     *
     * @param string $emptyText - what to say when there are none, e.g. "Nobody has logged in yet."
     *
     * @param RecentUseCollection $uses - the most recent, most recent first.
     */
    public function __construct(string $title, string $path, string $emptyText, RecentUseCollection $uses)
    {
        $this->m_title = $title;
        $this->m_path = $path;
        $this->m_emptyText = $emptyText;
        $this->m_uses = $uses;
    }


    protected function renderContent() : void
    {
?>
<section class="card widget">
    <h3><a href="<?= Html::e($this->m_path) ?>"><?= Html::e($this->m_title) ?></a></h3>
    <?php if (count($this->m_uses) === 0): ?>
        <p class="muted"><?= Html::e($this->m_emptyText) ?></p>
    <?php else: ?>
        <ul class="widget-list">
        <?php foreach ($this->m_uses as $use): ?>
            <?php /* @var $use RecentUse */ ?>
            <li>
                <a href="<?= Html::e($use->getPath()) ?>"><?= Html::e($use->getName()) ?></a>
                <span class="hint"><?= Html::e(Time::format($use->getUsedAt())) ?><?php if ($use->getIp() !== ''): ?>,
                    from <span class="mono"><?= Html::e($use->getIp()) ?></span><?php endif; ?></span>
            </li>
        <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>
<?php
    }
}
