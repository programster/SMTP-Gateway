<?php

/*
 * A provider's status, as a badge: disabled, enabled, or skipped for now (with why), as a provider that can't be
 * connected to is, for a while (see ProviderStatusRepository).
 */

declare(strict_types = 1);


class ViewProviderStatus extends Programster\AbstractView\AbstractView
{
    private readonly ProviderDefinition $m_provider;

    /** @var array<string, array{unavailable_until: string, last_error: string}> */
    private readonly array $m_unavailable;


    /**
     * @param ProviderDefinition $provider - the provider.
     *
     * @param array<string, array{unavailable_until: string, last_error: string}> $unavailable - the providers being
     * skipped, by name (see ProviderStatusRepository::loadUnavailableDetails()).
     */
    public function __construct(ProviderDefinition $provider, array $unavailable)
    {
        $this->m_provider = $provider;
        $this->m_unavailable = $unavailable;
    }


    protected function renderContent() : void
    {
        $name = $this->m_provider->getName();
?>
<?php if ($this->m_provider->isEnabled() === false): ?>
    <span class="badge">disabled</span>
<?php elseif (isset($this->m_unavailable[$name])): ?>
    <span class="badge badge-failed">skipped until
        <?= Html::e(Time::format($this->m_unavailable[$name]['unavailable_until'])) ?></span>
    <div class="hint"><?= Html::e($this->m_unavailable[$name]['last_error']) ?></div>
<?php else: ?>
    <span class="badge badge-sent">enabled</span>
<?php endif; ?>
<?php
    }
}
