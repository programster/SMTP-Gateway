<?php

/*
 * One provider's page: its type, status, place in the order, webhook, attempts, settings (with secrets never shown)
 * and rate limits, with buttons to edit it (ViewProviderFormPage) and remove it.
 */

declare(strict_types = 1);


class ViewProviderPage extends Programster\AbstractView\AbstractView
{
    private readonly ProviderDefinition $m_provider;
    private readonly ProvidersConfig $m_config;
    private readonly string $m_version;


    /**
     * @param ProviderDefinition $provider - the provider.
     *
     * @param ProvidersConfig $config - every provider, to say where this one is in the order.
     *
     * @param string $version - the version of the providers, for removing it.
     */
    public function __construct(ProviderDefinition $provider, ProvidersConfig $config, string $version)
    {
        $this->m_provider = $provider;
        $this->m_config = $config;
        $this->m_version = $version;
    }


    protected function renderContent() : void
    {
        $provider = $this->m_provider;
        $name = $provider->getName();
        $path = '/providers/' . rawurlencode($name);
        $counts = ProviderStats::loadRecentCounts();
        $noneSent = ['last_minute' => 0, 'last_hour' => 0, 'last_24_hours' => 0, 'recipients_last_24_hours' => 0];
        $sent = $counts[$name] ?? $noneSent;
        $unavailable = ProviderStatusRepository::loadUnavailableDetails();

        $attempts = [
            'Last minute' => $sent['last_minute'],
            'Last hour' => $sent['last_hour'],
            'Last 24 hours' => $sent['last_24_hours'],
            'Recipients (24h)' => $sent['recipients_last_24_hours'],
        ];
?>
<p><a href="/providers">Back to the providers</a></p>
<div class="page-heading">
    <h1><?= Html::e($name) ?></h1>
    <div class="page-actions">
        <a class="button" href="<?= Html::e("{$path}/edit") ?>"><?= new ViewIcon(Icon::EDIT) ?> Edit</a>
        <button type="button" class="secondary" data-role="remove-provider"
            data-action="<?= Html::e("{$path}/remove") ?>" data-name="<?= Html::e($name) ?>"><?= new ViewIcon(
                Icon::TRASH
            ) ?> Remove</button>
    </div>
</div>
<div class="card">
    <dl class="details">
        <dt>Type</dt><dd><?= Html::e($provider->getType()->getLabel()) ?></dd>
        <dt>Status</dt><dd><?= new ViewProviderStatus($provider, $unavailable) ?></dd>
        <dt>Order</dt><dd><?= Html::e($this->describePlace()) ?></dd>
        <?php if ($provider->getType()->hasWebhook()): ?>
            <dt>Webhook</dt><dd><code>&lt;PUBLIC_URL&gt;/webhooks/<?= Html::e($name) ?></code></dd>
        <?php endif; ?>
    </dl>
</div>
<h2>Attempts</h2>
<div class="tiles">
    <?php foreach ($attempts as $label => $value): ?>
        <div class="tile"><span class="tile-value"><?= number_format($value) ?></span><span
            class="tile-label"><?= Html::e($label) ?></span></div>
    <?php endforeach; ?>
</div>
<h2>Settings</h2>
<div class="table-wrap">
<table>
    <thead><tr><th>Setting</th><th>Value</th></tr></thead>
    <tbody>
    <?php foreach ($provider->getSettings()->getSettings() as $setting): ?>
        <?php /* @var $setting ConfigSetting */ ?>
        <tr><td><?= Html::e($this->getSettingLabel($setting)) ?><br><span
            class="hint mono"><?= Html::e($setting->getName()) ?></span></td><td><?= self::describeSetting(
                $setting
            ) ?></td></tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
<h2>Rate limits</h2>
<?php if ($provider->getRateLimits()->isEmpty()): ?>
    <p class="muted">None of its own: only the global rate limits, and each email token's, apply.</p>
<?php else: ?>
    <ul>
    <?php foreach ($provider->getRateLimits()->getLimits() as $limit): ?>
        <?php /* @var $limit RateLimit */ ?>
        <li><?= Html::e($limit->describe()) ?></li>
    <?php endforeach; ?>
    </ul>
    <p class="hint">These apply on top of the <a href="/rate-limits">global rate limits</a>, and each email token's.</p>
<?php endif; ?>
<?= new ViewRemoveProviderDialog($this->m_version) ?>
<script type="module" src="/js/ProviderPage.js"></script>
<?php
    }


    /**
     * Describe where the provider is in the order they're tried in (for the overflow and fallback modes).
     */
    private function describePlace() : string
    {
        $enabledNames = [];

        foreach ($this->m_config->getProviders() as $provider)
        {
            if ($provider->isEnabled())
            {
                $enabledNames[] = $provider->getName();
            }
        }

        $index = array_search($this->m_provider->getName(), $enabledNames, true);

        $place = ($index === false)
            ? 'Not tried, as it\'s disabled.'
            : ($index + 1) . ' of ' . count($enabledNames) . ' enabled providers.';

        return $place;
    }


    // A setting's label on the provider form (e.g. "SMTP password"), or its name, if the form doesn't have it.
    private function getSettingLabel(ConfigSetting $setting) : string
    {
        $label = $setting->getName();

        foreach (ProviderFormFields::getForType($this->m_provider->getType()) as $field)
        {
            $label = ($field->getKey() === $setting->getName()) ? $field->getLabel() : $label;
        }

        return $label;
    }


    /**
     * Describe a setting's value as HTML, without ever showing a secret that's written out in full.
     *
     * @param ConfigSetting $setting - the setting.
     *
     * @return string - HTML.
     */
    private static function describeSetting(ConfigSetting $setting) : string
    {
        $value = $setting->getValue();

        if ($value instanceof ConfigValue && $value->isReference())
        {
            $state = $value->isAvailable()
                ? '<span class="badge badge-sent">set</span>'
                : '<span class="badge badge-failed">not set</span>';

            $html = '<code>' . Html::e($value->toConfigString()) . '</code> ' . $state;
        }
        elseif ($value instanceof ConfigValue && $setting->isSecret() && $value->toConfigString() !== '')
        {
            $html =
                '&bull;&bull;&bull;&bull; <span class="hint">(written out in full: a <code>${ENV_VAR}</code>'
                . ' reference would keep it out of the file)</span>';
        }
        elseif ($value instanceof ConfigValue)
        {
            $html = ($value->toConfigString() === '')
                ? '<span class="muted">(none)</span>'
                : Html::e($value->toConfigString());
        }
        else
        {
            $html = Html::e((string)$value);
        }

        return $html;
    }
}
