<?php

/*
 * The form for adding a provider, or editing one. When adding, the type is chosen from a dropdown first, and only that
 * type's fields are shown (ProviderFormPage.js shows them, and disables the other types', so they aren't sent). When
 * editing, the type can't be changed: a provider of another type is a new provider. Each type's fields come from
 * ProviderFormFields, and are read back by ProviderFormInput.
 */

declare(strict_types = 1);


class ViewProviderFormPage extends Programster\AbstractView\AbstractView
{
    private readonly bool $m_isEditing;
    private readonly string $m_editingName;

    /** @var array<string, string> */
    private readonly array $m_values;

    /** @var array<string, bool> */
    private readonly array $m_secretsSet;

    private readonly RateLimitCollection $m_rateLimits;
    private readonly string $m_version;
    private readonly string $m_error;


    /**
     * @param array<string, string> $values - what the inputs are filled in with, by name (see ProviderFormValues).
     *
     * @param array<string, bool> $secretsSet - the secrets that have a value now, by input name.
     */
    private function __construct(
        bool $isEditing,
        string $editingName,
        array $values,
        array $secretsSet,
        RateLimitCollection $rateLimits,
        string $version,
        string $error
    )
    {
        $this->m_isEditing = $isEditing;
        $this->m_editingName = $editingName;
        $this->m_values = $values;
        $this->m_secretsSet = $secretsSet;
        $this->m_rateLimits = clone $rateLimits;
        $this->m_version = $version;
        $this->m_error = $error;
    }


    /**
     * Create the form for adding a provider.
     *
     * @param array<string, string> $values - what the inputs are filled in with (see ProviderFormValues).
     *
     * @param RateLimitCollection $rateLimits - the rate limits to fill in.
     *
     * @param string $version - the version of the providers it's based on.
     *
     * @param string $error - why it failed to save, or an empty string.
     *
     * @return ViewProviderFormPage
     */
    public static function createForAdding(
        array $values,
        RateLimitCollection $rateLimits,
        string $version,
        string $error
    ) : ViewProviderFormPage
    {
        return new ViewProviderFormPage(false, '', $values, [], $rateLimits, $version, $error);
    }


    /**
     * Create the form for editing a provider.
     *
     * @param ProviderDefinition $provider - the provider, as it's saved now.
     *
     * @param array<string, string> $values - what the inputs are filled in with (see ProviderFormValues).
     *
     * @param RateLimitCollection $rateLimits - the rate limits to fill in.
     *
     * @param string $version - the version of the providers it's based on.
     *
     * @param string $error - why it failed to save, or an empty string.
     *
     * @return ViewProviderFormPage
     */
    public static function createForEditing(
        ProviderDefinition $provider,
        array $values,
        RateLimitCollection $rateLimits,
        string $version,
        string $error
    ) : ViewProviderFormPage
    {
        $secretsSet = ProviderFormValues::findSecretsSet($provider);
        $name = $provider->getName();
        return new ViewProviderFormPage(true, $name, $values, $secretsSet, $rateLimits, $version, $error);
    }


    protected function renderContent() : void
    {
        $chosenType = ProviderType::tryFrom($this->m_values['type'] ?? '');
        $editingPath = '/providers/' . rawurlencode($this->m_editingName) . '/edit';
        $action = $this->m_isEditing ? $editingPath : '/providers/new';
        $title = $this->m_isEditing ? "Edit the provider \"{$this->m_editingName}\"" : 'Add a provider';
        $isEnabled = ($this->m_values['enabled'] ?? '') === '1';
?>
<h1><?= Html::e($title) ?></h1>
<p><a href="/providers">Back to the providers</a></p>
<?= new ViewFormError($this->m_error) ?>
<form method="post" action="<?= Html::e($action) ?>" class="card" id="provider-form">
    <?= new ViewCsrfField() ?>
    <input type="hidden" name="version" value="<?= Html::e($this->m_version) ?>">
    <?php if ($this->m_isEditing && $chosenType !== null): ?>
        <p>Type: <strong><?= Html::e($chosenType->getLabel()) ?></strong></p>
        <input type="hidden" name="type" value="<?= Html::e($chosenType->value) ?>" data-role="type">
        <p class="hint">A provider's type can't be changed. To use another, add a provider of that type.</p>
    <?php else: ?>
        <label for="provider-type">Type</label>
        <select id="provider-type" name="type" required data-role="type">
            <option value="">Choose the provider…</option>
            <?php foreach (ProviderType::cases() as $type): ?>
                <option value="<?= Html::e($type->value) ?>"
                    <?= ($type === $chosenType) ? 'selected' : '' ?>><?= Html::e($type->getLabel()) ?></option>
            <?php endforeach; ?>
        </select>
    <?php endif; ?>

    <label for="provider-name">Name</label>
    <input type="text" id="provider-name" name="name" required maxlength="50"
        value="<?= Html::e($this->m_values['name'] ?? '') ?>" placeholder="e.g. sendgrid-main">
    <p class="hint">Lowercase letters, digits, "-" and "_". Its emails are recorded under it, and a provider with a
        webhook receives its events at <code>/webhooks/&lt;name&gt;</code>, so renaming it starts its statistics and
        rate limits afresh, and needs its webhook pointing at the new name.</p>
    <?= new ViewToggle('enabled', 'Enabled: send emails through it', $isEnabled) ?>

    <?php foreach (ProviderType::cases() as $type): ?>
        <?php if ($this->m_isEditing === false || $type === $chosenType): ?>
            <?php $this->renderTypeFields($type, $type === $chosenType); ?>
        <?php endif; ?>
    <?php endforeach; ?>

    <h3>Rate limits</h3>
    <p class="hint">This provider's own limits, e.g. to stay within its plan. They apply on top of the global ones and
        each email token's.</p>
    <?= new ViewRateLimitFields($this->m_rateLimits) ?>

    <div class="form-actions"><button type="submit">Save</button> <a href="/providers">Cancel</a></div>
</form>
<script type="module" src="/js/ProviderFormPage.js"></script>
<?php
    }


    /**
     * Render one type's fields, in a fieldset of their own. Only the chosen type's is shown, and the others are
     * disabled, so they're neither required nor sent (ProviderFormPage.js switches them when the type is changed).
     *
     * @param ProviderType $type - the type.
     *
     * @param bool $isChosen - whether it's the type chosen.
     *
     * @return void
     */
    private function renderTypeFields(ProviderType $type, bool $isChosen) : void
    {
        $hidden = $isChosen ? '' : ' hidden disabled';
?>
<fieldset class="provider-type-fields" data-provider-type="<?= Html::e($type->value) ?>"<?= $hidden ?>>
    <legend><?= Html::e($type->getLabel()) ?></legend>
    <?php foreach (ProviderFormFields::getForType($type) as $field): ?>
        <?php $this->renderField($type, $field); ?>
    <?php endforeach; ?>
    <?php if ($type->hasWebhook()): ?>
        <p class="hint">Its events are posted to <code>&lt;PUBLIC_URL&gt;/webhooks/&lt;name&gt;</code>. See
            <a href="/documentation/providers#types-of-provider">Types of provider</a> for setting up its webhook.</p>
    <?php endif; ?>
</fieldset>
<?php
    }


    private function renderField(ProviderType $type, ProviderField $field) : void
    {
        $name = ProviderFormInput::getInputName($type, $field);
        $value = $this->m_values[$name] ?? '';
        $required = $field->isRequired() ? ' required' : '';
        $optional = $field->isRequired() ? '' : ' <span class="muted">(optional)</span>';
?>
<label for="<?= Html::e($name) ?>"><?= Html::e($field->getLabel()) ?><?= $optional ?></label>
<?php
        match ($field->getKind()) {
            ProviderFieldKind::TEXT => $this->renderInput('text', $name, $value, $required),
            ProviderFieldKind::NUMBER => $this->renderInput('number', $name, $value, $required),
            ProviderFieldKind::TEXTAREA => $this->renderTextarea($name, $value, $required),
            ProviderFieldKind::CHOICE => $this->renderChoice($name, $value, $field),
            ProviderFieldKind::SECRET => $this->renderSecret($name, $field),
        };

        if ($field->getHint() !== '')
        {
?>
<p class="hint"><?= Html::e($field->getHint()) ?></p>
<?php
        }
    }


    private function renderInput(string $inputType, string $name, string $value, string $required) : void
    {
?>
<input type="<?= $inputType ?>" id="<?= Html::e($name) ?>" name="<?= Html::e($name) ?>"
    value="<?= Html::e($value) ?>"<?= $required ?>>
<?php
    }


    private function renderTextarea(string $name, string $value, string $required) : void
    {
?>
<textarea id="<?= Html::e($name) ?>" name="<?= Html::e($name) ?>"<?= $required ?>><?= Html::e($value) ?></textarea>
<?php
    }


    private function renderChoice(string $name, string $value, ProviderField $field) : void
    {
?>
<select id="<?= Html::e($name) ?>" name="<?= Html::e($name) ?>" required>
    <?php foreach ($field->getOptions() as $optionValue => $optionLabel): ?>
        <option value="<?= Html::e((string)$optionValue) ?>"
            <?= ((string)$optionValue === $value) ? 'selected' : '' ?>><?= Html::e($optionLabel) ?></option>
    <?php endforeach; ?>
</select>
<?php
    }


    /**
     * Render a secret: a choice of entering it (it's never shown, and when editing, left blank keeps the one there
     * is), or reading it from an environment variable, by name.
     *
     * @param string $name - its input's name.
     *
     * @param ProviderField $field - the field.
     *
     * @return void
     */
    private function renderSecret(string $name, ProviderField $field) : void
    {
        $source = SecretSource::tryFrom($this->m_values["{$name}_source"] ?? '') ?? SecretSource::ENTERED;
        $isEntered = ($source === SecretSource::ENTERED);
        $isSet = isset($this->m_secretsSet[$name]);
        $placeholder = $isSet ? 'Set: leave blank to keep it' : '';
        $required = ($field->isRequired() && !$isSet && !$this->m_isEditing) ? ' required' : '';
        $envVar = $this->m_values["{$name}_env"] ?? '';
        $enteredHidden = $isEntered ? '' : ' hidden';
        $envHidden = $isEntered ? ' hidden' : '';
?>
<div class="secret-field" data-role="secret">
    <div class="secret-sources">
        <label class="inline"><input type="radio" name="<?= Html::e("{$name}_source") ?>"
            value="<?= SecretSource::ENTERED->value ?>" data-role="source" <?= $isEntered ? 'checked' : '' ?>>
            Enter it here</label>
        <label class="inline"><input type="radio" name="<?= Html::e("{$name}_source") ?>"
            value="<?= SecretSource::ENVIRONMENT->value ?>" data-role="source" <?= $isEntered ? '' : 'checked' ?>>
            Read it from an environment variable</label>
    </div>
    <div data-source="<?= SecretSource::ENTERED->value ?>"<?= $enteredHidden ?>>
        <input type="password" id="<?= Html::e($name) ?>" name="<?= Html::e($name) ?>" autocomplete="new-password"
            placeholder="<?= Html::e($placeholder) ?>"<?= $isEntered ? $required : '' ?>>
        <p class="hint">Written into <?= Html::e(SettingsFile::getPath()) ?>. It's never shown again.</p>
    </div>
    <div data-source="<?= SecretSource::ENVIRONMENT->value ?>"<?= $envHidden ?>>
        <input type="text" name="<?= Html::e("{$name}_env") ?>" value="<?= Html::e($envVar) ?>"
            aria-label="<?= Html::e($field->getLabel()) ?>: the environment variable" pattern="[A-Z_][A-Z0-9_]*">
        <p class="hint">Written as <code>${NAME}</code>, which keeps the secret out of the file. Add the variable to
            <code>.env</code>, and restart, for it to be read.</p>
    </div>
</div>
<?php
    }
}
