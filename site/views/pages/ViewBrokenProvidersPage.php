<?php

/*
 * Shown when the providers in settings.yaml aren't valid (e.g. the file was edited by hand), so they can be fixed, or
 * when the whole file isn't valid YAML, so it can be fixed as a whole. Nothing can be sent until it is.
 */

declare(strict_types = 1);


class ViewBrokenProvidersPage extends Programster\AbstractView\AbstractView
{
    private readonly string $m_loadError;
    private readonly string $m_yaml;
    private readonly string $m_version;
    private readonly string $m_error;
    private readonly ProvidersEditScope $m_scope;


    public function __construct(
        string $loadError,
        string $yaml,
        string $version,
        string $error,
        ProvidersEditScope $scope
    )
    {
        $this->m_loadError = $loadError;
        $this->m_yaml = $yaml;
        $this->m_version = $version;
        $this->m_error = $error;
        $this->m_scope = $scope;
    }


    protected function renderContent() : void
    {
?>
<h1>Providers</h1>
<p class="flash flash-error" role="alert">No email can be sent (it's queued, and sent once this is fixed):
    <?= Html::e($this->m_loadError) ?></p>
<?php if ($this->m_scope === ProvidersEditScope::WHOLE_FILE): ?>
<p class="hint">The whole of <code><?= Html::e(SettingsFile::getPath()) ?></code> is shown below as it is (including any
    secrets written out in full), as none of it can be read until it's fixed. Its other settings (on the Settings page)
    have their defaults until then.</p>
<?php else: ?>
<p class="hint">The providers are shown below as they're written (including any secrets written out in full), so they
    can be fixed.</p>
<?php endif; ?>
<?= new ViewProvidersEditor($this->m_yaml, $this->m_version, $this->m_error, $this->m_scope) ?>
<?php
    }
}
