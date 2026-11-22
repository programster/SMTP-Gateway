<?php

/*
 * One provider's page: its id, type, status, place in the order, webhook, the email tokens restricted to it, attempts,
 * settings (with secrets never shown) and rate limits, with buttons to edit it (ViewProviderFormPage), disable it and
 * remove it.
 */

declare(strict_types = 1);


class ViewProviderPage extends Programster\AbstractView\AbstractView
{
    private readonly ProviderDefinition $m_provider;
    private readonly ProvidersConfig $m_config;
    private readonly ProviderActivity $m_activity;
    private readonly string $m_version;
    private readonly string $m_newWebhookToken;


    /**
     * @param ProviderDefinition $provider - the provider.
     *
     * @param ProvidersConfig $config - every provider, to say where this one is in the order.
     *
     * @param ProviderActivity $activity - how the providers are being used.
     *
     * @param string $version - the version of the providers, for removing it.
     *
     * @param string $newWebhookToken - a webhook token that's just been generated, to show this once, or an empty
     * string.
     */
    public function __construct(
        ProviderDefinition $provider,
        ProvidersConfig $config,
        ProviderActivity $activity,
        string $version,
        string $newWebhookToken = ''
    )
    {
        $this->m_newWebhookToken = $newWebhookToken;
        $this->m_provider = $provider;
        $this->m_config = $config;
        $this->m_activity = $activity;
        $this->m_version = $version;
    }


    protected function renderContent() : void
    {
        $provider = $this->m_provider;
        $name = $provider->getName();
        $path = '/providers/' . rawurlencode($name);
        $counts = $this->m_activity->getRecentCounts();
        $noneSent = ['last_minute' => 0, 'last_hour' => 0, 'last_24_hours' => 0, 'recipients_last_24_hours' => 0];
        $sent = $counts[$name] ?? $noneSent;
        $unavailable = $this->m_activity->getUnavailable();
        $links = $this->m_activity->getTokenLinks();
        $removal = ProviderRemovalNotice::createForProvider($provider, $this->m_config, $links);
        $linked = $links->filterLinkedTo($provider->getId());
        $strandedIfDisabled = $links->filterStrandedWithout($provider->getId(), $this->m_config);

        $attempts = [
            'Last minute' => $sent['last_minute'],
            'Last hour' => $sent['last_hour'],
            'Last 24 hours' => $sent['last_24_hours'],
            'Recipients (24h)' => $sent['recipients_last_24_hours'],
        ];
?>
<p><a href="/providers">Back to the providers</a></p>
<h1><?= Html::e($name) ?></h1>
<div class="card">
    <dl class="details">
        <dt>ID</dt><dd><code><?= Html::e($provider->getId()->getValue()) ?></code><br><span class="hint">What an
            email token's provider condition refers to it by, in the API. It stays the same if it's renamed.</span></dd>
        <dt>Type</dt><dd><?= Html::e($provider->getType()->getLabel()) ?></dd>
        <dt>Status</dt><dd><?= new ViewProviderStatus($provider, $unavailable) ?></dd>
        <dt>Order</dt><dd><?= Html::e($this->describePlace()) ?></dd>
        <?php if ($provider->getType()->hasWebhook()): ?>
            <dt>Webhook</dt><dd><?= $this->describeWebhookUrl() ?></dd>
        <?php endif; ?>
    </dl>
    <div class="form-actions">
        <a class="button" href="<?= Html::e("{$path}/edit") ?>"><?= new ViewIcon(Icon::EDIT) ?> Edit</a>
        <?php $this->renderEnabledToggle($path, $removal->isRefused()); ?>
        <button type="button" class="danger" data-role="remove-provider" data-action="<?= Html::e("{$path}/remove")
            ?>" data-name="<?= Html::e($name) ?>" data-warning="<?= Html::e($removal->getMessage()) ?>"
            data-refused="<?= $removal->isRefused() ? 'true' : 'false' ?>"><?= new ViewIcon(Icon::TRASH) ?> Remove this
            provider</button>
    </div>
    <?php $this->renderSmtp2GoWebhookSetup($path); ?>
    <?php if ($provider->isEnabled() && count($strandedIfDisabled) > 0): ?>
        <p class="hint">Disabling it would leave these email tokens with no enabled provider, so their emails would
            wait in the queue until it's enabled again: <?= Html::e($strandedIfDisabled->describeNames()) ?>.</p>
    <?php endif; ?>
</div>
<h2>Senders it takes</h2>
<?php if ($provider->getAllowedSenders() instanceof AddressRuleSet): ?>
    <?php $senderRows = ProviderSenderRows::createUsingRestriction($provider->getAllowedSenders()); ?>
    <p>It only takes email whose sender is at any of these domains, or is any of these addresses (the envelope
        sender, and the From and Sender headers, must all be):</p>
    <div class="grid-2">
        <div><h3>Domains</h3><pre><?= Html::e(implode("\n", $senderRows->getDomains()) ?: 'None') ?></pre></div>
        <div><h3>Addresses</h3><pre><?= Html::e(implode("\n", $senderRows->getAddresses()) ?: 'None') ?></pre></div>
    </div>
<?php else: ?>
    <p class="muted">Anyone: it isn't restricted to particular senders.</p>
<?php endif; ?>
<h2>Email tokens restricted to it</h2>
<?php if (count($linked) === 0): ?>
    <p class="muted">None. Email tokens that aren't restricted to particular providers can use it too, while it's
        enabled.</p>
<?php else: ?>
    <ul>
    <?php foreach ($linked as $link): ?>
        <?php /* @var $link ProviderTokenLink */ ?>
        <?php $tokenPath = '/email-tokens/' . rawurlencode((string)$link->getEmailToken()->getId()); ?>
        <li><a href="<?= Html::e($tokenPath) ?>"><?= Html::e($link->getEmailToken()->getName()) ?></a></li>
    <?php endforeach; ?>
    </ul>
    <p class="hint">Email tokens that aren't restricted to particular providers can use it too, while it's
        enabled.</p>
<?php endif; ?>
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
     * For an SMTP2GO provider, say whether its webhook has a secret yet, with a button to set it up if it has an API
     * key, and the steps to do it by hand. Other types of provider show nothing here.
     *
     * @param string $path - the provider's page.
     *
     * @return void
     */
    private function renderSmtp2GoWebhookSetup(string $path) : void
    {
        $settings = $this->m_provider->getSettings();

        if ($settings instanceof Smtp2GoSettings)
        {
            $token = $this->m_newWebhookToken;
            $hasSecret = ($settings->getWebhookSecret() !== '' || $token !== '');
            $isOpen = (!$settings->hasApiKey() || $token !== '');
            $url = ProviderWebhookUrl::get($this->m_provider->getName());
            $address = ($url === '') ? '/webhooks/' . rawurlencode($this->m_provider->getName()) : $url;
            $events = implode(', ', Smtp2GoWebhook::RECORDED_EVENTS);
?>
<h3>Delivery events</h3>
<?php if ($hasSecret === false): ?>
    <p class="flash flash-warning" role="status">Deliveries and bounces aren't being recorded until its webhook is set
        up.</p>
<?php endif; ?>
<?php if ($settings->hasApiKey()): ?>
    <form method="post" action="<?= Html::e("{$path}/webhook") ?>" class="inline-form">
        <?= new ViewCsrfField() ?>
        <input type="hidden" name="version" value="<?= Html::e($this->m_version) ?>">
        <button type="submit"><?= $hasSecret ? 'Set up the webhook again' : 'Set up the webhook' ?></button>
    </form>
    <p class="hint">Needs the API key to have the webhook view, add and edit permissions, and <code>PUBLIC_URL</code>
        to be this service's public https address. SMTP2GO's free plan only allows 1 webhook, and paid plans 10, so
        the account needs room for this one.</p>
<?php endif; ?>
<details class="advanced"<?= $isOpen ? ' open' : '' ?>>
    <summary>Set it up by hand</summary>
    <?php if (!str_starts_with($url, 'https://')): ?>
        <p class="flash flash-warning" role="status">First set <code>PUBLIC_URL</code> in .env to this service's public
            https address, and recreate its container, as SMTP2GO has to be able to reach it.</p>
    <?php endif; ?>
    <ol id="smtp2go-webhook-steps">
        <li>
            <?php if ($token !== ''): ?>
                <strong>Copy this bearer token now. It won't be shown again:</strong>
                <?php $this->renderCopyableValue('smtp2go-webhook-token', $token); ?>
            <?php else: ?>
                Generate this provider's bearer token, before adding the webhook in SMTP2GO:
                <form method="post" action="<?= Html::e("{$path}/webhook/token") ?>">
                    <?= new ViewCsrfField() ?>
                    <input type="hidden" name="version" value="<?= Html::e($this->m_version) ?>">
                    <?php if ($hasSecret): ?>
                        <p class="hint">It already has a token, which can't be shown again. A new one replaces it, and
                            its webhook stops working until you update it in SMTP2GO.</p>
                        <label><input type="checkbox" name="confirm_replace" value="1" required> Replace its
                            token</label>
                    <?php endif; ?>
                    <button type="submit"><?= $hasSecret ? 'Generate a new token' : 'Generate a token' ?></button>
                </form>
            <?php endif; ?>
        </li>
        <li>Open SMTP2GO's webhooks page for your account's region, in a new tab:
            <?php foreach (Smtp2GoAppRegion::cases() as $region): ?>
                <a href="<?= Html::e($region->getWebhooksUrl()) ?>" target="_blank"
                    rel="noopener noreferrer"><?= Html::e($region->getLabel()) ?></a><?=
                    ($region === Smtp2GoAppRegion::GLOBAL) ? '.' : ',' ?>
            <?php endforeach; ?>
            Then press <strong>Add Webhook</strong>.
        </li>
        <li>Set its <strong>URL</strong> to:
            <?php $this->renderCopyableValue('smtp2go-webhook-url', $address); ?>
        </li>
        <li>Set its <strong>Authorization header</strong> to <strong>Bearer</strong>, and paste the token as its value,
            on its own.</li>
        <li>Leave <strong>Users</strong> empty, so it covers all of them, and set <strong>Output type</strong> to
            <strong>JSON</strong>.</li>
        <li>Under <strong>Email events to include</strong>, tick:
            <?php $this->renderCopyableValue('smtp2go-webhook-events', $events); ?>
        </li>
        <li>Press <strong>Save changes</strong>.</li>
        <li>Check it's switched on, by running:
            <pre>curl -i -X POST <?= Html::e($address) ?></pre>
            <span class="hint"><strong>401</strong> is right: it's on, and refused this request for having no secret.
                <strong>404</strong> means the secret isn't saved. Anything else, such as 403 or a login page, means
                something in front of this service is blocking it.</span>
        </li>
        <li>Press <strong>Test this webhook</strong> in SMTP2GO, then send an email through this provider. Its page
            under <strong>Activity &gt; Emails</strong> should show it as delivered within a minute or so.</li>
    </ol>
</details>
<?php
        }
    }


    /**
     * Render a read-only value with a button to copy it.
     *
     * @param string $id - the input's ID.
     *
     * @param string $value - the value.
     *
     * @return void
     */
    private function renderCopyableValue(string $id, string $value) : void
    {
?>
<div class="secret-box"><input type="text" id="<?= Html::e($id) ?>" value="<?= Html::e($value) ?>" readonly><button
    type="button" class="secondary" data-copy-target="<?= Html::e($id) ?>">Copy</button></div>
<?php
    }


    /**
     * Render the button that disables the provider, keeping its settings, or enables it again.
     *
     * @param string $path - the provider's page, e.g. "/providers/sendgrid-main".
     *
     * @param bool $isOnlyEnabled - whether it's the only enabled provider, which can't be disabled, as no email could
     * be sent.
     *
     * @return void
     */
    private function renderEnabledToggle(string $path, bool $isOnlyEnabled) : void
    {
        $isEnabled = $this->m_provider->isEnabled();
        $action = $isEnabled ? "{$path}/disable" : "{$path}/enable";
        $label = $isEnabled ? 'Disable' : 'Enable';
        $refused = '';

        if ($isOnlyEnabled)
        {
            $why =
                'It\'s the only enabled provider, so it can\'t be disabled, as no email could be sent. Enable another'
                . ' one first.';

            $refused = ' disabled title="' . Html::e($why) . '"';
        }
?>
<form method="post" action="<?= Html::e($action) ?>" class="inline-form">
    <?= new ViewCsrfField() ?>
    <input type="hidden" name="version" value="<?= Html::e($this->m_version) ?>">
    <button type="submit" class="secondary"<?= $refused ?>><?= Html::e($label) ?></button>
</form>
<?php
    }


    /**
     * Describe where the provider is in the order they're tried in.
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


    /**
     * Describe where the provider's events are to be posted, as HTML. If PUBLIC_URL isn't set, only the path is known.
     */
    private function describeWebhookUrl() : string
    {
        $name = $this->m_provider->getName();
        $url = ProviderWebhookUrl::get($name);

        $html = ($url === '')
            ? '<code>/webhooks/' . Html::e(rawurlencode($name)) . '</code> on this site <span class="hint">(set'
                . ' <code>PUBLIC_URL</code> in .env for its full address)</span>'
            : '<code>' . Html::e($url) . '</code>';

        return $html;
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

        if ($value instanceof ConfigValue && $setting->isSecret() && $value->toConfigString() !== '')
        {
            $html = '&bull;&bull;&bull;&bull; <span class="hint">(set, never shown)</span>';
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
