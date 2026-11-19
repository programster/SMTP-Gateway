<?php

/*
 * The editor for the providers: the YAML of settings.yaml's providers section (or the whole file, while it isn't valid
 * YAML), and the version it was based on, so saving can tell if someone else changed it in the meantime.
 */

declare(strict_types = 1);


class ViewProvidersEditor extends Programster\AbstractView\AbstractView
{
    private readonly string $m_yaml;
    private readonly string $m_version;
    private readonly string $m_error;
    private readonly ProvidersEditScope $m_scope;


    public function __construct(string $yaml, string $version, string $error, ProvidersEditScope $scope)
    {
        $this->m_yaml = $yaml;
        $this->m_version = $version;
        $this->m_error = $error;
        $this->m_scope = $scope;
    }


    protected function renderContent() : void
    {
        $label = ($this->m_scope === ProvidersEditScope::WHOLE_FILE) ? 'settings.yaml' : 'The providers';
?>
<h2 id="edit">Edit <?= Html::e(lcfirst($label)) ?></h2>
<?= new ViewFormError($this->m_error) ?>
<form method="post" action="/providers#edit" class="card">
    <?= new ViewCsrfField() ?>
    <input type="hidden" name="version" value="<?= Html::e($this->m_version) ?>">
    <input type="hidden" name="scope" value="<?= Html::e($this->m_scope->value) ?>">
    <label for="yaml"><?= Html::e($label) ?></label>
    <textarea id="yaml" name="yaml" class="yaml-editor" spellcheck="false"
        required><?= Html::e($this->m_yaml) ?></textarea>
    <p class="hint">
        Secrets (passwords, keys and tokens) are shown as
            <code><?= Html::e(ProvidersConfigParser::UNCHANGED) ?></code>, and are kept as they are unless you replace
            them.
        They're checked before they're saved (in the providers section of
            <code><?= Html::e(SettingsFile::getPath()) ?></code>), and the queue workers use them from their next
            email.
    </p>
    <div class="form-actions"><button type="submit">Save</button> <a href="/providers">Discard changes</a></div>
</form>
<?php
    }
}
