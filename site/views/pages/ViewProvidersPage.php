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
        $links = EmailTokenRepository::loadProviderLinks();
        $systemSenderWarning = SystemSenderWarning::describe($this->m_config);
        $mode = $this->m_config->getMode();
?>
<h1>Providers</h1>
<?php if ($systemSenderWarning !== ''): ?>
    <p class="flash flash-warning" role="status"><?= Html::e($systemSenderWarning) ?></p>
<?php endif; ?>
<p>Every email is sent through one of these, by the queue workers. They're saved in the providers section of
    <code><?= Html::e(SettingsFile::getPath()) ?></code>, and can also be changed through the API.</p>
<p><a class="button" href="/providers/new">Add a provider</a></p>
<div class="card">
    <p class="hint">Each provider's own rate limits apply on top of the global ones (under
        <a href="/rate-limits">Global rate limits</a>) and each email token's. A provider that can't be connected to
        is skipped for <?= ProviderStatusRepository::UNAVAILABLE_SECONDS ?> seconds, then tried again.</p>
    <p><a class="button secondary" href="/providers/export">Download settings.yaml</a> <span class="hint">All the
        settings, including these providers, as a backup. <strong>It holds every password, key and token in
        full</strong>, so keep it as safe as they are: don't commit it to version control, or share it.</span></p>
</div>
<h2>Order</h2>
<p class="hint">Drag a provider by its handle to change the order they're tried in. Alternatively, select the handle
    using Tab on your keyboard, and then press the up/down arrow keys to change the order. A disabled provider keeps
    its place, and is skipped.</p>
<div class="table-wrap">
<table class="providers-table">
    <thead><tr><th><span class="visually-hidden">Order</span></th><th>Provider</th>
        <th class="num">Attempts (minute / hour / 24h)</th><th>Enabled</th>
        <th><span class="visually-hidden">Actions</span></th></tr></thead>
    <tbody id="provider-rows">
    <?php foreach ($this->m_config->getProviders() as $provider): ?>
        <?php $this->renderRow($provider, $counts, $unavailable, $links); ?>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
<p class="flash flash-error provider-toggle-error" id="provider-toggle-error" role="alert" hidden></p>
<form id="provider-toggle-form" hidden>
    <?= new ViewCsrfField() ?>
    <input type="hidden" name="version" value="<?= Html::e($this->m_version) ?>">
</form>
<form method="post" action="/providers/order" id="provider-order" class="card order-bar" hidden>
    <?= new ViewCsrfField() ?>
    <input type="hidden" name="version" value="<?= Html::e($this->m_version) ?>">
    <div data-role="names"></div>
    <span>The order has changed.</span>
    <button type="submit">Save the order</button>
    <a class="button secondary" href="/providers">Undo</a>
</form>
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
     * Render a provider's row: a handle to drag it into another place in the order, its name (a link to its page),
     * type, attempts, a toggle that enables or disables it straight away, and buttons to edit and remove it.
     *
     * @param ProviderDefinition $provider - the provider.
     *
     * @param array<string, array<string, int>> $counts - every provider's attempts (see ProviderStats).
     *
     * @param array<string, array{unavailable_until: string, last_error: string}> $unavailable - the providers being
     * skipped (see ProviderStatusRepository).
     *
     * @param ProviderTokenLinkCollection $links - the email tokens restricted to particular providers, for the warning
     * before it's removed.
     *
     * @return void
     */
    private function renderRow(
        ProviderDefinition $provider,
        array $counts,
        array $unavailable,
        ProviderTokenLinkCollection $links
    ) : void
    {
        $name = $provider->getName();
        $path = '/providers/' . rawurlencode($name);
        $noneSent = ['last_minute' => 0, 'last_hour' => 0, 'last_24_hours' => 0, 'recipients_last_24_hours' => 0];
        $sent = $counts[$name] ?? $noneSent;
        $removal = ProviderRemovalNotice::createForProvider($provider, $this->m_config, $links);
        $isEnabled = $provider->isEnabled();
        $rowClass = $isEnabled ? '' : ' class="is-provider-off"';
?>
<tr data-role="sortable-row" data-name="<?= Html::e($name) ?>"<?= $rowClass ?>>
    <td class="drag-cell"><button type="button" class="icon-button drag-handle" data-role="drag-handle"
        aria-label="Move <?= Html::e($name) ?>: drag it, or use the up and down arrow keys"
        title="Drag to change the order"><?= new ViewIcon(Icon::DRAG_HANDLE) ?></button></td>
    <td class="provider-info"><a href="<?= Html::e($path) ?>"><strong><?= Html::e($name) ?></strong></a><br><span
        class="hint"><?= Html::e($provider->getType()->getLabel()) ?></span></td>
    <td class="num provider-info"><?= number_format($sent['last_minute']) ?> / <?= number_format($sent['last_hour'])
        ?> / <?= number_format($sent['last_24_hours']) ?><br><span
        class="hint"><?= number_format($sent['recipients_last_24_hours']) ?> recipients in 24h</span></td>
    <td>
        <label class="toggle" title="Enable or disable it. This saves straight away."><input type="checkbox"
            role="switch" data-role="provider-enabled" data-path="<?= Html::e($path) ?>"
            aria-label="<?= Html::e("Enabled: {$name}") ?>"<?= $isEnabled ? ' checked' : '' ?>><span
            class="toggle-track" aria-hidden="true"></span></label>
        <?php if (isset($unavailable[$name])): ?>
            <div class="provider-skip"><span class="badge badge-failed">skipped until
                <?= Html::e(Time::format($unavailable[$name]['unavailable_until'])) ?></span>
                <div class="hint"><?= Html::e($unavailable[$name]['last_error']) ?></div></div>
        <?php endif; ?>
    </td>
    <td class="row-actions">
        <a class="icon-button neutral" href="<?= Html::e("{$path}/edit") ?>" aria-label="Edit <?= Html::e($name) ?>"
            title="Edit"><?= new ViewIcon(Icon::EDIT) ?></a>
        <button type="button" class="icon-button" data-role="remove-provider"
            data-action="<?= Html::e("{$path}/remove") ?>" data-name="<?= Html::e($name) ?>"
            data-warning="<?= Html::e($removal->getMessage()) ?>"
            data-refused="<?= $removal->isRefused() ? 'true' : 'false' ?>"
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
