<?php

/*
 * One section of the service's settings (every section of settings.yaml but the providers has a page of its own), in
 * a form that saves it.
 */

declare(strict_types = 1);


class ViewSettingsPage extends Programster\AbstractView\AbstractView
{
    private readonly ServiceSettings $m_settings;
    private readonly CloudflareNetworksStatus $m_cloudflareStatus;
    private readonly SettingsSection $m_section;
    private readonly string $m_error;

    /** @var array<string, string> */
    private readonly array $m_submitted;


    /**
     * @param ServiceSettings $settings - the current settings.
     *
     * @param CloudflareNetworksStatus $cloudflareStatus - Cloudflare's address ranges that are stored.
     *
     * @param SettingsSection $section - the section to show (or the one that failed to save), whose page is shown,
     * with any other sections shown on it (e.g. the sending settings, on the General page). Not the providers, which
     * have a page of their own.
     *
     * @param string $error - why its form failed to save, or an empty string.
     *
     * @param array<string, string> $submitted - what its form sent, to fill it in with again, if it failed to save.
     *
     * @throws LogicException - if it's given the providers' section.
     */
    public function __construct(
        ServiceSettings $settings,
        CloudflareNetworksStatus $cloudflareStatus,
        SettingsSection $section,
        string $error,
        array $submitted
    )
    {
        if ($section === SettingsSection::PROVIDERS)
        {
            throw new LogicException('The providers have a page of their own (see ViewProvidersPage).');
        }

        $this->m_settings = $settings;
        $this->m_cloudflareStatus = $cloudflareStatus;
        $this->m_section = $section;
        $this->m_error = $error;
        $this->m_submitted = $submitted;
    }


    protected function renderContent() : void
    {
        $settings = $this->m_settings;
        $serverResourcesPath = SettingsController::SERVER_RESOURCES_PATH;
?>
<h1>Settings</h1>
<p>How the service behaves. Each page of settings is saved on its own, in
    <code><?= Html::e(SettingsFile::getPath()) ?></code>, and applies straight away. The providers email is sent through
    are on the <a href="/providers">Providers</a> page. The settings for how the service is deployed (e.g. the database,
    <code>PUBLIC_URL</code>, and the limits in <a href="<?= $serverResourcesPath ?>">Server resources</a>) are in
    <code>.env</code>.</p>
<?php foreach ($settings->getProblems() as $problem): ?>
    <p class="flash flash-error" role="alert"><?= Html::e($problem) ?></p>
<?php endforeach; ?>
<?php
        // Each section shown on the page, the first being the page's own.
        match ($this->m_section->getPageSection()) {
            SettingsSection::GENERAL => $this->renderGeneralPage(),
            SettingsSection::SECURITY => $this->renderSecurity(),
            SettingsSection::PROXIES => $this->renderProxies(),
            SettingsSection::SENDING => throw new LogicException('The sending settings are on the General page.'),
            SettingsSection::RETENTION => $this->renderRetention(),
            SettingsSection::GOOGLE_SSO => $this->renderGoogleSso(),
            SettingsSection::PROVIDERS => throw new LogicException('The providers have a page of their own.'),
        };
?>
<script type="module" src="/js/SettingsPage.js"></script>
<?php
    }


    // The General page: the general settings, then the sending settings, in a card of their own.
    private function renderGeneralPage() : void
    {
        $this->renderGeneral();
        $this->renderSending();
    }


    private function renderGeneral() : void
    {
        $section = SettingsSection::GENERAL;
        $current = $this->getValue($section, 'timezone', $this->m_settings->getTimezone()->getName());
        $timezones = array_unique(['UTC', ...DateTimeZone::listIdentifiers()]);
        $format = $this->m_settings->getDateTimeFormat();

        // What each dropdown has chosen: what was sent, if saving failed (so nothing chosen, if it was made up), or
        // the format now.
        $order = DateOrder::tryFrom($this->getValue($section, 'date_order', $format->getOrder()->value));
        $separatorNow = $format->getSeparator()->value;
        $separator = DateSeparator::tryFrom($this->getValue($section, 'date_separator', $separatorNow));
        $positionNow = $format->getTimePosition()->value;
        $position = TimePosition::tryFrom($this->getValue($section, 'time_position', $positionNow));
        $now = new DateTimeImmutable('now', $this->m_settings->getTimezone());
?>
<h2 id="<?= $section->value ?>">General</h2>
<?php $this->renderFormStart($section); ?>
    <label for="timezone">Time zone</label>
    <select id="timezone" name="timezone">
        <?php foreach ($timezones as $timezone): ?>
            <option value="<?= Html::e($timezone) ?>"<?= ($timezone === $current) ? ' selected' : '' ?>><?=
                Html::e($timezone) ?></option>
        <?php endforeach; ?>
    </select>
    <p class="hint">The time zone that times are shown in, in the web interface (e.g. the logs). Times are always
        stored in UTC, whatever this is set to.</p>

    <h3>How dates and times are written</h3>
    <div class="grid-3">
        <div>
            <label for="date_order">Date order</label>
            <select id="date_order" name="date_order">
                <?php foreach (DateOrder::cases() as $case): ?>
                    <?php $example = (new DateTimeFormat($case, DateSeparator::DASH, TimePosition::AFTER_DATE)); ?>
                    <option value="<?= $case->value ?>"<?= ($case === $order) ? ' selected' : '' ?>><?= Html::e(
                        "{$case->getLabel()}: {$example->formatDate($now)}"
                    ) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label for="date_separator">Separator</label>
            <select id="date_separator" name="date_separator">
                <?php foreach (DateSeparator::cases() as $case): ?>
                    <option value="<?= Html::e($case->value) ?>"<?= ($case === $separator) ? ' selected' : '' ?>><?=
                        Html::e("{$case->getLabel()}: {$case->value}") ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label for="time_position">Time</label>
            <select id="time_position" name="time_position">
                <?php foreach (TimePosition::cases() as $case): ?>
                    <option value="<?= $case->value ?>"<?= ($case === $position) ? ' selected' : '' ?>><?= Html::e(
                        $case->getLabel()
                    ) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>
    <p class="hint">Times use a 24-hour clock. They're written like this now:
        <strong><?= Html::e($format->format($now, '')) ?></strong>.</p>
<?php
        $this->renderFormEnd();
    }


    private function renderSecurity() : void
    {
        $section = SettingsSection::SECURITY;
        $security = $this->m_settings->getSecurity();
        $isMfaRequired = $this->getValue($section, 'require_mfa', $security->isMfaRequired() ? '1' : '') === '1';
        $policy = $security->getPasswordPolicy();
        $minLength = $this->getValue($section, 'password_min_length', (string)$policy->getMinLength());

        // Each rule's toggle: its name, label, and whether it's ticked now (or was, when saving failed).
        $rules = [
            ['password_requires_capital', 'A capital letter', $policy->requiresCapital()],
            ['password_requires_number', 'A number', $policy->requiresNumber()],
            ['password_requires_symbol', 'A symbol (anything but a letter or number)', $policy->requiresSymbol()],
        ];
?>
<h2 id="<?= $section->value ?>">Security</h2>
<?php $this->renderFormStart($section); ?>
    <?= new ViewToggle('require_mfa', 'Every account must use two-factor authentication', $isMfaRequired) ?>
    <p class="hint">An authenticator app (TOTP), or a security key such as a YubiKey (WebAuthn). When it's required, an
        account without one can log in with its password, but can only reach "My account" until it has set one up, and
        can't remove its last one. The API and SMTP server aren't affected, as they use API keys and email tokens.</p>

    <h3>Passwords</h3>
    <label for="password_min_length">Minimum length (characters)</label>
    <input type="number" id="password_min_length" name="password_min_length" min="<?= PasswordPolicy::LOWEST_MIN_LENGTH
        ?>" max="<?= PasswordPolicy::HIGHEST_MIN_LENGTH ?>" value="<?= Html::e($minLength) ?>" required>
    <p class="hint">From <?= PasswordPolicy::LOWEST_MIN_LENGTH ?> to <?= PasswordPolicy::HIGHEST_MIN_LENGTH ?>. Length
        matters far more than the mix of characters: a long passphrase is stronger than a short password with a
        symbol in it.</p>
    <fieldset class="choices">
        <legend>Must also have</legend>
        <?php foreach ($rules as [$name, $label, $isRequired]): ?>
            <?php $isTicked = ($this->getValue($section, $name, $isRequired ? '1' : '') === '1'); ?>
            <?= new ViewToggle($name, $label, $isTicked) ?>
        <?php endforeach; ?>
        <p class="hint">Checked whenever a password is set: an account added with one, a password changed, or one
            reset. Existing passwords aren't checked again, so new rules apply to each account's next password.</p>
    </fieldset>
<?php
        $this->renderFormEnd();
    }


    private function renderProxies() : void
    {
        $section = SettingsSection::PROXIES;
        $settings = $this->m_settings->getProxies();
        $proxies = [];

        foreach ($settings->getTrustedProxies() as $proxy)
        {
            /* @var $proxy IpNetwork */
            $proxies[] = (string)$proxy;
        }

        $cloudflareNow = $settings->isBehindCloudflare() ? '1' : '';
        $isBehindCloudflare = ($this->getValue($section, 'behind_cloudflare', $cloudflareNow) === '1');
        $isErrored = ($section === $this->m_section && $this->m_error !== '');

        // The rows (see ViewValueRows): as they were sent, if saving them failed, or the proxies saved now.
        $getSubmitted = function (string $name) : string {
            return (string)($this->m_submitted[$name] ?? '');
        };

        $submittedRows = array_values(PolicyInput::readValueRowsFromForm($getSubmitted, 'trusted_proxies'));
        $proxyValues = $isErrored ? $submittedRows : $proxies;
        $ipCheck = ValueRowCheck::IP_NETWORK;
        $proxyRows = new ViewValueRows('trusted_proxies', 'Trusted proxy', $ipCheck, '172.16.0.0/12', ...$proxyValues);
?>
<h2 id="<?= $section->value ?>">Proxies</h2>
<p>What sits in front of the web interface, so the real IP address of each request can be worked out, for the IP
    restrictions on API keys and email tokens, and in the logs.</p>
<?php $this->renderFormStart($section); ?>
    <h3>Cloudflare</h3>
    <?= new ViewToggle('behind_cloudflare', 'This site is behind Cloudflare', $isBehindCloudflare) ?>
    <p class="hint">Requests from Cloudflare then have their <code>CF-Connecting-IP</code> header believed, as the
        client's IP address. Cloudflare's own addresses are handled separately: they're fetched from Cloudflare (on
        startup, every 6 hours, and when this is turned on) and kept up to date in the database, so don't add them to
        the trusted proxies below.</p>

    <h3>Trusted proxies</h3>
    <?= $proxyRows ?>
    <p class="hint">Your own reverse proxies (e.g. Traefik) in front of the web interface: their IP addresses or CIDR
        ranges, one per row (a pasted list is split into rows). Their <code>X-Forwarded-For</code> header is believed,
        so a client can't fake its own IP address to get round an IP restriction. Behind Cloudflare, these are the
        proxies between Cloudflare and this service (Cloudflare sends to your proxy, which sends here), and the client
        is still the one Cloudflare says. Responses go back the same way. Leave it empty if nothing of your own sits in
        front of the container. A range covering every address (e.g. <code>0.0.0.0/0</code>) isn't allowed, as it would
        let any client fake its address.</p>
<?php
        $this->renderFormEnd();

        if ($settings->isBehindCloudflare())
        {
            $this->renderCloudflareStatus($this->m_cloudflareStatus);
        }
    }


    /**
     * Render how many of Cloudflare's ranges are stored, and when they were fetched, with a button to fetch them now.
     *
     * @param CloudflareNetworksStatus $status - the ranges stored.
     *
     * @return void
     */
    private function renderCloudflareStatus(CloudflareNetworksStatus $status) : void
    {
?>
<form method="post" action="<?= SettingsController::CLOUDFLARE_FETCH_PATH ?>" class="card cloudflare-status">
    <?= new ViewCsrfField() ?>
    <?php if ($status->hasBeenFetched()): ?>
        <p>Cloudflare's <?= $status->getCount() ?> address ranges were last fetched
            <?= Html::e(Time::format($status->getFetchedAt())) ?>.</p>
    <?php else: ?>
        <p class="flash flash-warning">Cloudflare's address ranges haven't been fetched yet, so its
            <code>CF-Connecting-IP</code> header isn't believed, and every request seems to come from Cloudflare. Check
            that this server can reach <code>www.cloudflare.com</code>, and fetch them.</p>
    <?php endif; ?>
    <button type="submit" class="secondary">Fetch them now</button>
</form>
<?php
    }


    private function renderSending() : void
    {
        $section = SettingsSection::SENDING;
        $sending = $this->m_settings->getSending();
        $currentFrom = $sending->hasSystemEmailFrom() ? (string)$sending->getSystemEmailFrom() : '';
        $from = $this->getValue($section, 'system_email_from', $currentFrom);
        $waitSeconds = $this->getValue($section, 'sync_wait_seconds', (string)$sending->getSyncWaitSeconds());
        $max = SendingSettings::MAX_SYNC_WAIT_SECONDS;
?>
<h2 id="<?= $section->value ?>">Sending</h2>
<?php $this->renderFormStart($section); ?>
    <label for="system_email_from">The service's own emails are sent from</label>
    <input type="email" id="system_email_from" name="system_email_from" value="<?= Html::e($from) ?>"
        placeholder="noreply@example.org">
    <p class="hint">The address the service's own emails (password reset links) are sent from, through the providers.
        They must allow sending from it (e.g. a verified sender in SendGrid). Forgotten passwords can only be reset by
        email when this and <code>PUBLIC_URL</code> are set. Otherwise, or if no provider is working, reset one on the
        server with <code>php /var/www/site/scripts/reset-password.php &lt;email&gt;</code>.</p>
    <label for="sync_wait_seconds">How long a client waits to be told an email was sent (seconds)</label>
    <input type="number" id="sync_wait_seconds" name="sync_wait_seconds" min="0" max="<?= $max ?>"
        value="<?= Html::e($waitSeconds) ?>" required>
    <p class="hint">From 0 to <?= $max ?>. The SMTP server and the send endpoint only wait when it looks like a
        worker will start sending the email within this time (it's not held up by a rate limit or a long queue), and
        otherwise say straight away that it was queued. 0 never waits, and always replies "queued".</p>
<?php
        $this->renderFormEnd();
    }


    private function renderRetention() : void
    {
        $section = SettingsSection::RETENTION;

        try
        {
            $retention = $this->m_settings->getRetention();
        }
        catch (ConfigurationException)
        {
            // The section is invalid (and listed at the top), so the form starts with the defaults, to fix it with.
            $retention = RetentionSettings::createDefault();
        }

        $policy = $retention->getEmailRetention();
        $recordDays = $this->getValue($section, 'email_record_days', (string)$policy->getRecordDays());
        $logDays = $this->getValue($section, 'log_days', (string)$retention->getLogDays());
?>
<h2 id="<?= $section->value ?>">Email retention</h2>
<?php $this->renderFormStart($section); ?>
    <p class="hint">Each part of an email fits within the one before it. Until an email has been sent, it's always
        kept whole, as it's needed to send it.</p>
    <label for="email_record_days">Email records (days)</label>
    <input type="number" id="email_record_days" name="email_record_days" min="1" value="<?= Html::e($recordDays) ?>"
        required>
    <p class="hint">The record of each email (who it was from and to, its subject, and what happened to it), and of
        each rejected request. The email's content goes with it.</p>
    <?php // Side by side on a wide window, as each is a choice of the same three kinds. ?>
    <div class="grid-2">
<?php
        $this->renderRetentionChoice(
            'content',
            'Email content',
            'as long as the record',
            $policy->getContentSetting(),
            'The whole email once it has been sent (or has failed, or was cancelled), so it can be downloaded as a .eml'
            . ' file from the web interface or the API. Deleted by the nightly clean up once its time is up.'
        );

        $this->renderRetentionChoice(
            'attachment',
            'Attachments',
            'as long as the content',
            $policy->getAttachmentSetting(),
            'Every part of a kept email other than its plain text and HTML body, inline images included. When they\'re'
            . ' removed, the rest of the content is kept, with a short attachments-removed.txt in their place listing'
            . ' each one\'s name, type and size. Attachments are what takes up space, so they aren\'t kept by default.'
        );
?>
    </div>
    <label for="log_days">Logs (days)</label>
    <input type="number" id="log_days" name="log_days" min="1" value="<?= Html::e($logDays) ?>" required>
    <p class="hint">The logs, shown under Logs.</p>
<?php
        $this->renderFormEnd();
    }


    /**
     * Show the choice of how long a part of each email is kept: not kept, as long as what it's part of, or a number
     * of days.
     *
     * @param string $prefix - the fields' prefix, e.g. "content" for content_mode and content_days.
     *
     * @param string $label - what's kept, e.g. "Email content".
     *
     * @param string $withParent - what keeping it as long as what it's part of means, e.g. "as long as the record".
     *
     * @param int $setting - the current setting: -1, 0, or a number of days.
     *
     * @param string $hint - the explanation.
     *
     * @return void
     */
    private function renderRetentionChoice(
        string $prefix,
        string $label,
        string $withParent,
        int $setting,
        string $hint
    ) : void
    {
        $currentMode = match (true) {
            $setting === -1 => RetentionMode::NOT_KEPT,
            $setting === 0 => RetentionMode::KEPT_WITH_PARENT,
            default => RetentionMode::KEPT_FOR_DAYS,
        };

        $section = SettingsSection::RETENTION;
        $mode = $this->getValue($section, "{$prefix}_mode", $currentMode->value);
        $days = $this->getValue($section, "{$prefix}_days", ($setting > 0) ? (string)$setting : '30');

        $choices = [
            RetentionMode::NOT_KEPT->value => 'Not kept: deleted as soon as the email has been sent',
            RetentionMode::KEPT_WITH_PARENT->value => "Kept for {$withParent}",
            RetentionMode::KEPT_FOR_DAYS->value => 'Kept for a number of days:',
        ];
?>
    <fieldset class="choices">
        <legend><?= Html::e($label) ?></legend>
        <?php foreach ($choices as $value => $description): ?>
            <?php $checked = ($mode === (string)$value) ? ' checked' : ''; ?>
            <label class="choice"><input type="radio" name="<?= $prefix ?>_mode"
                value="<?= Html::e((string)$value) ?>"<?= $checked ?>> <span><?= Html::e($description) ?></span></label>
        <?php endforeach; ?>
        <input type="number" name="<?= $prefix ?>_days" min="1" value="<?= Html::e($days) ?>"
            aria-label="<?= Html::e($label) ?>, in days">
        <p class="hint"><?= Html::e($hint) ?></p>
    </fieldset>
<?php
    }


    private function renderGoogleSso() : void
    {
        $section = SettingsSection::GOOGLE_SSO;
        $googleSso = $this->m_settings->getGoogleSso();
        $isEnabled = $this->getValue($section, 'enabled', $googleSso->isEnabled() ? '1' : '') === '1';
        $clientId = $this->getValue($section, 'client_id', $googleSso->getClientId());
        $bypass = $this->getValue($section, 'bypass_mfa', $googleSso->getMfaBypass()->value);
        $secret = $googleSso->getClientSecret();
        $hasSecret = ($secret->toConfigString() !== '');
        $config = Config::get();

        $callbackUrl = $config->hasPublicUrl()
            ? $config->getPublicUrl() . '/login/google/callback'
            : '(set PUBLIC_URL in .env first)';

        // Each choice's label and one-line summary. The help dialog explains each in full.
        $bypassChoices = [
            SsoMfaBypass::NEVER->value => ['Never', 'Google only replaces the password.'],
            SsoMfaBypass::AUTO->value => ['Auto', 'Skipped when Google confirms two-step verification.'],
            SsoMfaBypass::ALWAYS->value => ['Always', 'Google is enough on its own.'],
        ];

        $bypassHelp = new HelpEntryCollection(
            new HelpEntry(
                'Never',
                'Google only replaces the password. An account with a second factor still has to give it, and'
                . ' requiring a second factor (under Security) still applies.'
            ),
            new HelpEntry(
                'Auto',
                'The second factor is skipped only when Google confirms the person used two-step verification.'
                . ' Google doesn\'t say how someone logged in at the moment, so for now this is the same as Never,'
                . ' which errs on the safe side. It starts skipping the second factor as soon as Google does.'
            ),
            new HelpEntry(
                'Always',
                'Google is enough on its own. Only choose this if every Google account that can log in has two-step'
                . ' verification turned on (e.g. enforced for your Google Workspace domain), as this service can\'t'
                . ' tell.'
            )
        );

        $helpTitle = 'Skipping the second factor';
        $helpFootnote = 'Logging in with the password always needs the second factor, whichever this is.';
?>
<h2 id="<?= $section->value ?>">SSO</h2>
<p>Logging in to the web interface with another service's account, rather than a password.</p>
<?php $this->renderFormStart($section); ?>
    <h3>Google</h3>
    <?= new ViewToggle('enabled', 'Allow accounts to log in with Google', $isEnabled) ?>
    <p class="hint">Only existing accounts can: the Google account's (verified) email address has to match the
        account's. It needs <code>PUBLIC_URL</code>, and an OAuth client ID of type "Web application" from the Google
        Cloud console (APIs &amp; Services &gt; Credentials), with this as an authorised redirect URI:
        <code><?= Html::e($callbackUrl) ?></code></p>
    <label for="client_id">Client ID</label>
    <input type="text" id="client_id" name="client_id" value="<?= Html::e($clientId) ?>" autocomplete="off"
        spellcheck="false">
    <label for="client_secret">Client secret</label>
    <input type="password" id="client_secret" name="client_secret" autocomplete="new-password"
        placeholder="<?= $hasSecret ? 'Leave empty to keep the current secret' : '' ?>">
    <p class="hint"><?= $hasSecret ? 'Set.' : 'Not set.' ?> It's never shown, once saved.</p>
    <fieldset class="choices">
        <legend>Whether logging in with Google skips this service's own second factor
            <?= new ViewHelpButton('google-mfa-help', $helpTitle) ?></legend>
        <?php foreach ($bypassChoices as $value => [$choiceLabel, $summary]): ?>
            <label class="choice"><input type="radio" name="bypass_mfa" value="<?= Html::e((string)$value) ?>"<?=
                ($bypass === (string)$value) ? ' checked' : '' ?>> <span><strong><?= Html::e($choiceLabel) ?></strong>
                <span class="hint"><?= Html::e($summary) ?></span></span></label>
        <?php endforeach; ?>
        <p class="hint"><?= Html::e($helpFootnote) ?></p>
    </fieldset>
    <?= new ViewHelpDialog('google-mfa-help', $helpTitle, $bypassHelp, $helpFootnote) ?>
<?php
        $this->renderFormEnd();
    }


    /**
     * Start a section's form, which saves to its page: why it failed to save, if it did, and its version.
     * @param SettingsSection $section
     * @return void
     */
    private function renderFormStart(SettingsSection $section) : void
    {
        // Why it failed to save goes with the form that failed, as a page can have more than one.
        $error = ($section === $this->m_section) ? $this->m_error : '';
?>
<?= new ViewFormError($error) ?>
<form method="post" action="<?= Html::e($section->getSavePath()) ?>" class="card settings-form">
    <?= new ViewCsrfField() ?>
    <input type="hidden" name="version" value="<?= Html::e(self::fetchVersion($section)) ?>">
<?php
    }


    /**
     * Get the version of a section, for its form to be saved against, so a change made meanwhile isn't overwritten.
     * @param SettingsSection $section - the section.
     * @return string
     */
    private static function fetchVersion(SettingsSection $section) : string
    {
        try
        {
            $version = SettingsFile::getSectionVersion($section);
        }
        catch (ConfigurationException)
        {
            // The file can't be read, so none of it can be saved until it's fixed (see the providers page).
            $version = hash('sha256', '');
        }

        return $version;
    }


    private function renderFormEnd() : void
    {
?>
    <div class="form-actions"><button type="submit">Save</button></div>
</form>
<?php
    }


    /**
     * Get what to fill a field in with: what was sent, if it's in the form that failed to save, or its current value.
     *
     * @param SettingsSection $section - the field's section.
     *
     * @param string $name - the field's name.
     *
     * @param string $current - its current value.
     *
     * @return string
     */
    private function getValue(SettingsSection $section, string $name, string $current) : string
    {
        $isErrored = ($section === $this->m_section && $this->m_error !== '');

        return $isErrored
            ? ($this->m_submitted[$name] ?? '')
            : $current;
    }
}
