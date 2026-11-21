<?php

/*
 * The two tabs of a list of things that are either active or not (the API keys, email tokens and users), each a link
 * with how many it holds. Active is the default, so the others are out of the way unless someone asks to see them.
 */

declare(strict_types = 1);


class ViewListTabs extends Programster\AbstractView\AbstractView
{
    private readonly string $m_path;
    private readonly string $m_otherLabel;
    private readonly string $m_otherParam;
    private readonly int $m_activeCount;
    private readonly int $m_otherCount;
    private readonly bool $m_isShowingOther;


    /**
     * @param string $path - the page, e.g. "/api-keys".
     *
     * @param string $otherLabel - what the second tab is called, e.g. "Revoked".
     *
     * @param string $otherParam - the query parameter that's "1" when the second tab is open, e.g. "revoked".
     *
     * @param int $activeCount - how many are active.
     *
     * @param int $otherCount - how many are on the second tab.
     *
     * @param bool $isShowingOther - whether the second tab is the one open.
     */
    public function __construct(
        string $path,
        string $otherLabel,
        string $otherParam,
        int $activeCount,
        int $otherCount,
        bool $isShowingOther
    )
    {
        $this->m_path = $path;
        $this->m_otherLabel = $otherLabel;
        $this->m_otherParam = $otherParam;
        $this->m_activeCount = $activeCount;
        $this->m_otherCount = $otherCount;
        $this->m_isShowingOther = $isShowingOther;
    }


    protected function renderContent() : void
    {
        $activeCurrent = $this->m_isShowingOther ? '' : ' aria-current="page"';
        $otherCurrent = $this->m_isShowingOther ? ' aria-current="page"' : '';
        $otherPath = $this->m_path . '?' . http_build_query([$this->m_otherParam => '1']);
?>
<nav class="list-tabs" aria-label="Active or <?= Html::e(strtolower($this->m_otherLabel)) ?>">
    <a href="<?= Html::e($this->m_path) ?>"<?= $activeCurrent ?>>Active <span
        class="tab-count"><?= number_format($this->m_activeCount) ?></span></a>
    <a href="<?= Html::e($otherPath) ?>"<?= $otherCurrent ?>><?= Html::e($this->m_otherLabel) ?> <span
        class="tab-count"><?= number_format($this->m_otherCount) ?></span></a>
</nav>
<?php
    }
}
