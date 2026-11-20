<?php

/*
 * The fields of an email token, for creating one or changing one: its name, the lists of IP addresses, senders,
 * reply-to addresses and recipients it's restricted to, the providers it may use, its priority and its rate limits.
 * Their values are read back by PolicyInput::readPolicyFromForm(). The lists and rate limits are rows added one at a
 * time (see DynamicRows), so the page needs DynamicRows.createForPage().
 */

declare(strict_types = 1);


class ViewEmailTokenFields extends Programster\AbstractView\AbstractView
{
    private readonly array $m_values;
    private readonly EmailTokenFormPurpose $m_purpose;
    private readonly ProviderDefinitionCollection $m_providers;


    /**
     * @param array<string, string> $values - the fields' values, by name, e.g. what was sent when it was invalid, or
     * the token's as it is now (see EmailTokenFormValues).
     *
     * @param EmailTokenFormPurpose $purpose - whether it's creating a token, or changing one.
     *
     * @param ProviderDefinitionCollection $providers - the providers in settings.yaml (enabled or not), in order, which
     * it can be restricted to. Empty before any have been set up.
     */
    public function __construct(
        array $values,
        EmailTokenFormPurpose $purpose,
        ProviderDefinitionCollection $providers
    )
    {
        $this->m_values = $values;
        $this->m_purpose = $purpose;
        $this->m_providers = $providers;
    }


    protected function renderContent() : void
    {
        $value = function (string $name) : string {
            return Html::e((string)($this->m_values[$name] ?? ''));
        };

        $getField = function (string $name) : string {
            return (string)($this->m_values[$name] ?? '');
        };

        // The values of a list's rows (see ViewValueRows), as they were sent, or as the controller filled them in.
        $rows = function (string $prefix) use ($getField) : array {
            return array_values(PolicyInput::readValueRowsFromForm($getField, $prefix));
        };

        $ipValues = $rows('allowed_ips');
        $ipCheck = ValueRowCheck::IP_NETWORK;
        $ipRows = new ViewValueRows('allowed_ips', 'Allowed IP address', $ipCheck, '10.0.0.0/8', ...$ipValues);

        try
        {
            $limits = PolicyInput::readRateLimitsFromForm($getField)->getLimits();
        }
        catch (InvalidInputException)
        {
            // Leave the rows empty, as the error is shown at the top.
            $limits = new RateLimitCollection();
        }

        $ipFilledIn = match ($this->m_purpose) {
            EmailTokenFormPurpose::CREATING => ' This has been filled in with the address you\'re using now.',
            EmailTokenFormPurpose::EDITING => '',
        };
?>
    <label for="name">Name</label>
    <input type="text" id="name" name="name" value="<?= $value('name') ?>" placeholder="e.g. Billing service" required
        maxlength="255">
    <p class="hint">To recognise the token by, e.g. the service that will use it.</p>

    <h3>Allowed IP addresses</h3>
    <?= $ipRows ?>
    <p class="hint">One per row: a single address, or a CIDR range for many (e.g. <code>10.0.0.0/8</code>), IPv4 or
        IPv6. The token is refused from anywhere else.<?= Html::e($ipFilledIn) ?> Leave it empty to allow any IP
        address, which isn't recommended: a leaked token would then work from anywhere.</p>

    <p class="hint">Each address list below is optional: leave it empty to allow anyone. Otherwise put one rule per
        row: <code>*@example.org</code> for anyone at a domain (not its subdomains), or a single address such as
        <code>someone@example.org</code>.</p>
    <div class="grid-2">
        <div>
            <h3>Allowed senders</h3>
            <?= $this->createRuleRows('allowed_senders', 'Allowed sender', 'noreply@example.org', $rows) ?>
            <p class="hint">Who the email may say it's from: checked against the envelope sender (MAIL FROM) and the
                From and Sender headers.</p>
        </div>
        <div>
            <h3>Allowed reply-to addresses</h3>
            <?= $this->createRuleRows('allowed_reply_tos', 'Allowed reply-to address', 'support@example.org', $rows) ?>
            <p class="hint">Checked against the Reply-To header. An email without one is always fine.</p>
        </div>
        <div>
            <h3>Allowed recipients</h3>
            <?= $this->createRuleRows('allowed_recipients', 'Allowed recipient', '*@example.org', $rows) ?>
            <p class="hint">Who the token may send to: To, Cc and Bcc alike.</p>
        </div>
        <div>
            <label for="priority">Priority</label>
            <input type="number" id="priority" name="priority" min="1"
                max="<?= Priority::MAX_EMAIL_TOKEN_PRIORITY ?>" step="1"
                value="<?= ($value('priority') === '') ? Priority::DEFAULT : $value('priority') ?>" required>
            <p class="hint">From 1 (the lowest, and the default) to <?= Priority::MAX_EMAIL_TOKEN_PRIORITY ?>. Emails
                from a higher priority token are sent before those from a lower one, though every rate limit still
                applies. The service's own emails (password reset links) always go first.</p>
        </div>
        <div>
            <h3>Rate limits</h3>
            <?= new ViewRateLimitFields($limits) ?>
            <p class="hint">Optional: leave them empty for no limit. Each can limit the emails handed to the provider
                (each is one request, however many recipients it has), or the recipients, in any mix. They apply on top
                of the global limits. An email over a limit isn't refused: it's queued, and sent once every limit
                allows. Over SMTP, an email with more recipients than a recipient limit allows is split by the client
                into several.</p>
        </div>
        <div class="provider-restriction">
            <h3>Providers</h3>
            <?php $this->renderProviderChoices($getField) ?>
        </div>
    </div>
<?php
    }


    /**
     * Render the providers the token may use: a toggle to restrict it, and a box to tick for each provider (shown only
     * while it's on).
     *
     * @param Closure(string): string $getField - gets a field's value, by its name.
     *
     * @return void
     */
    private function renderProviderChoices(Closure $getField) : void
    {
        $isRestricted = ($getField('restrict_providers') === '1');
        $toggle = new ViewToggle('restrict_providers', 'Only send through particular providers', $isRestricted);

        if (count($this->m_providers) === 0)
        {
?>
            <p class="hint">There are no providers yet, so it can't be restricted to any. Its emails will go through
                whichever providers are added, and it can be restricted to some of them once they are.</p>
<?php
        }
        else
        {
?>
            <?= $toggle ?>
            <fieldset class="provider-choices">
                <legend class="visually-hidden">The providers it may use</legend>
<?php
            foreach ($this->m_providers as $provider)
            {
                /* @var $provider ProviderDefinition */
                $field = "allowed_provider_{$provider->getId()}";
                $checked = ($getField($field) === '1') ? ' checked' : '';
                $disabled = $provider->isEnabled() ? '' : ' (disabled)';
?>
                <label class="checkbox-option"><input type="checkbox" name="<?= Html::e($field) ?>" value="1"<?=
                    $checked ?>><?= Html::e($provider->getName() . $disabled) ?></label>
<?php
            }
?>
            </fieldset>
            <p class="hint">When it's off, its emails go through any of the enabled providers, as the mode decides.
                When it's on, they only go through the ticked ones that are enabled, with the mode applied to those as
                if they were the only providers. While none of them is enabled, its emails wait in the queue.</p>
<?php
        }
    }


    /**
     * Create the rows of one of the address lists.
     *
     * @param string $prefix - the rows' name, e.g. "allowed_senders".
     *
     * @param string $label - what each row is, for screen readers.
     *
     * @param string $placeholder - what an empty row shows.
     *
     * @param Closure(string): list<string> $rows - gets a list's values, by its prefix.
     *
     * @return ViewValueRows
     */
    private function createRuleRows(string $prefix, string $label, string $placeholder, Closure $rows) : ViewValueRows
    {
        return new ViewValueRows($prefix, $label, ValueRowCheck::ADDRESS_RULE, $placeholder, ...$rows($prefix));
    }
}
