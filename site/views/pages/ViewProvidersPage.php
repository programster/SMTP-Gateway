<?php

/*
 * The providers in settings.yaml: the enabled ones (in the order they're tried, which they're dragged to change), then
 * the disabled ones, each with how much it has sent and whether it's being skipped, a link to its own page (see
 * ViewProviderPage), and buttons to edit and remove it. Below them are the mode, and (as an advanced option) the YAML
 * editor.
 */

declare(strict_types = 1);


class ViewProvidersPage extends Programster\AbstractView\AbstractView
{
    private const string SENDER_CHECKS_INTRO =
        'Whether each enabled provider\'s account is set up as this service needs, and whether email from every'
        . ' active token\'s allowed senders will be accepted and trusted through them.';

    private readonly ProvidersConfig $m_config;
    private readonly string $m_yaml;
    private readonly string $m_version;
    private readonly string $m_error;


    /**
     * @param ProvidersConfig $config - the current configuration.
     *
     * @param string $yaml - the YAML for the editor (the current configuration, or what failed to save).
     *
     * @param string $version - the version the YAML was based on.
     *
     * @param string $error - why saving failed, or an empty string.
     */
    public function __construct(ProvidersConfig $config, string $yaml, string $version, string $error)
    {
        $this->m_config = $config;
        $this->m_yaml = $yaml;
        $this->m_version = $version;
        $this->m_error = $error;
    }


    protected function renderContent() : void
    {
        $counts = ProviderStats::loadRecentCounts();
        $unavailable = ProviderStatusRepository::loadUnavailableDetails();
        $mode = $this->m_config->getMode();
        $enabled = [];
        $disabled = [];

        foreach ($this->m_config->getProviders() as $provider)
        {
            /* @var $provider ProviderDefinition */
            if ($provider->isEnabled())
            {
                $enabled[] = $provider;
            }
            else
            {
                $disabled[] = $provider;
            }
        }
?>
<h1>Providers</h1>
<p>Every email is sent through one of these, by the queue workers. They're saved in the providers section of
    <code><?= Html::e(SettingsFile::getPath()) ?></code>, and can also be changed through the API.</p>
<p><a class="button" href="/providers/new">Add a provider</a></p>
<div class="card">
    <p class="hint">Each provider's own rate limits apply on top of the global ones (under
        <a href="/rate-limits">Global rate limits</a>) and each email token's. A provider that can't be connected to
        is skipped for <?= ProviderStatusRepository::UNAVAILABLE_SECONDS ?> seconds, then tried again.</p>
    <p><a class="button secondary" href="/providers/export">Download settings.yaml</a> <span class="hint">All the
        settings, including these providers. Safe to commit to version control: secrets written out in full are
        replaced with <code>${ENV_VAR}</code> references.</span></p>
</div>
<h2>Enabled</h2>
<p class="hint">Drag a provider by its handle (or focus the handle, and use the up and down arrow keys) to change the
    order they're tried in, for the overflow and fallback modes.</p>
<div class="table-wrap">
<table class="providers-table">
    <thead><tr><th><span class="visually-hidden">Order</span></th><th>Provider</th>
        <th class="num">Attempts (minute / hour / 24h)</th><th>Status</th>
        <th><span class="visually-hidden">Actions</span></th></tr></thead>
    <tbody id="enabled-providers">
    <?php foreach ($enabled as $provider): ?>
        <?php $this->renderRow($provider, $counts, $unavailable, true); ?>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
<form method="post" action="/providers/order" id="provider-order" class="card order-bar" hidden>
    <?= new ViewCsrfField() ?>
    <input type="hidden" name="version" value="<?= Html::e($this->m_version) ?>">
    <div data-role="names"></div>
    <span>The order has changed.</span>
    <button type="submit">Save the order</button>
    <a class="button secondary" href="/providers">Undo</a>
</form>
<?php if (count($disabled) > 0): ?>
<h2>Disabled</h2>
<p class="hint">Not used for sending, until they're enabled again (on their edit form). They go after the enabled
    providers in the order.</p>
<div class="table-wrap">
<table class="providers-table">
    <thead><tr><th>Provider</th><th class="num">Attempts (minute / hour / 24h)</th><th>Status</th><th><span
        class="visually-hidden">Actions</span></th></tr></thead>
    <tbody>
    <?php foreach ($disabled as $provider): ?>
        <?php $this->renderRow($provider, $counts, $unavailable, false); ?>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
<?php endif; ?>
<?php $this->renderModeForm($mode); ?>
<?= new ViewSenderChecks('/providers/sender-checks', self::SENDER_CHECKS_INTRO) ?>
<details class="card advanced"<?= ($this->m_error !== '') ? ' open' : '' ?>>
    <summary>Advanced: edit the providers as YAML</summary>
    <?= new ViewProvidersEditor($this->m_yaml, $this->m_version, $this->m_error, ProvidersEditScope::SECTION) ?>
</details>
<?= new ViewRemoveProviderDialog($this->m_version) ?>
<script type="module" src="/js/ProvidersPage.js"></script>
<?php
    }


    /**
     * Render a provider's row: its name (a link to its page), type, attempts and status, and buttons to edit and
     * remove it. An enabled provider's row also has a handle, to drag it into another place in the order.
     *
     * @param ProviderDefinition $provider - the provider.
     *
     * @param array<string, array<string, int>> $counts - every provider's attempts (see ProviderStats).
     *
     * @param array<string, array{unavailable_until: string, last_error: string}> $unavailable - the providers being
     * skipped (see ProviderStatusRepository).
     *
     * @param bool $isSortable - whether it has a handle to drag it by.
     *
     * @return void
     */
    private function renderRow(ProviderDefinition $provider, array $counts, array $unavailable, bool $isSortable) : void
    {
        $name = $provider->getName();
        $path = '/providers/' . rawurlencode($name);
        $noneSent = ['last_minute' => 0, 'last_hour' => 0, 'last_24_hours' => 0, 'recipients_last_24_hours' => 0];
        $sent = $counts[$name] ?? $noneSent;
?>
<tr data-role="sortable-row" data-name="<?= Html::e($name) ?>">
    <?php if ($isSortable): ?>
        <td class="drag-cell"><button type="button" class="icon-button drag-handle" data-role="drag-handle"
            aria-label="Move <?= Html::e($name) ?>: drag it, or use the up and down arrow keys"
            title="Drag to change the order"><?= new ViewIcon(Icon::DRAG_HANDLE) ?></button></td>
    <?php endif; ?>
    <td><a href="<?= Html::e($path) ?>"><strong><?= Html::e($name) ?></strong></a><br><span
        class="hint"><?= Html::e($provider->getType()->getLabel()) ?></span></td>
    <td class="num"><?= number_format($sent['last_minute']) ?> / <?= number_format($sent['last_hour']) ?> /
        <?= number_format($sent['last_24_hours']) ?><br><span
        class="hint"><?= number_format($sent['recipients_last_24_hours']) ?> recipients in 24h</span></td>
    <td><?= new ViewProviderStatus($provider, $unavailable) ?></td>
    <td class="row-actions">
        <a class="icon-button neutral" href="<?= Html::e("{$path}/edit") ?>" aria-label="Edit <?= Html::e($name) ?>"
            title="Edit"><?= new ViewIcon(Icon::EDIT) ?></a>
        <button type="button" class="icon-button" data-role="remove-provider"
            data-action="<?= Html::e("{$path}/remove") ?>" data-name="<?= Html::e($name) ?>"
            aria-label="Remove <?= Html::e($name) ?>" title="Remove"><?= new ViewIcon(Icon::TRASH) ?></button>
    </td>
</tr>
<?php
    }


    /**
     * Render the form for choosing how emails are shared between the providers, as a radio group, with what each
     * mode does beside it.
     *
     * @param ProviderMode $current - the mode now.
     *
     * @return void
     */
    private function renderModeForm(ProviderMode $current) : void
    {
?>
<h2>How emails are shared between them</h2>
<form method="post" action="/providers/mode" class="card mode-form">
    <?= new ViewCsrfField() ?>
    <input type="hidden" name="version" value="<?= Html::e($this->m_version) ?>">
    <?php foreach (ProviderMode::cases() as $mode): ?>
        <label class="mode-option">
            <input type="radio" name="mode" value="<?= Html::e($mode->value) ?>"
                <?= ($mode === $current) ? 'checked' : '' ?>>
            <span><strong><?= Html::e($mode->getLabel()) ?></strong>
                <span class="hint"><?= Html::e($mode->getExplanation()) ?></span></span>
        </label>
    <?php endforeach; ?>
    <p class="hint">Only enabled providers are used, in the order they're listed above.</p>
    <div class="form-actions"><button type="submit">Save the mode</button></div>
</form>
<?php
    }
}
