<?php

/*
 * The form for creating an email token, and choosing its restrictions (see ViewEmailTokenFields), with the curl
 * command that would create the same token through the management API.
 */

declare(strict_types = 1);


class ViewNewEmailTokenPage extends Programster\AbstractView\AbstractView
{
    private readonly array $m_values;
    private readonly string $m_error;
    private readonly ProviderDefinitionCollection $m_providers;
    private readonly string $m_siteUrl;


    /**
     * @param array $values - the form's values (field name to string), e.g. what was sent when it was invalid.
     *
     * @param string $error - why it was invalid, or an empty string.
     *
     * @param string $siteUrl - the address the site is reached at, for the curl command (e.g.
     * "https://smtp.example.org").
     *
     * @param ProviderDefinitionCollection $providers - the providers it can be restricted to.
     */
    public function __construct(array $values, string $error, string $siteUrl, ProviderDefinitionCollection $providers)
    {
        $this->m_values = $values;
        $this->m_error = $error;
        $this->m_providers = $providers;
        $this->m_siteUrl = $siteUrl;
    }


    protected function renderContent() : void
    {
?>
<h1>Create an email token</h1>
<?= new ViewFormError($this->m_error) ?>
<form method="post" action="/email-tokens" class="card" id="email-token-form">
    <?= new ViewCsrfField() ?>
    <?= new ViewEmailTokenFields($this->m_values, EmailTokenFormPurpose::CREATING, $this->m_providers) ?>
    <div class="form-actions"><button type="submit">Create the email token</button>
        <a href="/email-tokens">Cancel</a></div>
</form>
<section class="card api-command" id="api-command" data-site-url="<?= Html::e($this->m_siteUrl) ?>">
    <div class="page-heading">
        <h3>Creating it through the API</h3>
        <button type="button" class="secondary" data-role="copy">Copy</button>
    </div>
    <p class="hint">The same email token, created with the management API, as the form is filled in now. Put one of
        your <a href="/api-keys">API keys</a> in place of <code>&lt;your API key&gt;</code>.</p>
    <pre data-role="command">curl \
  --request POST \
  --header 'Authorization: Bearer &lt;your API key&gt;' \
  --header 'Content-Type: application/json' \
  --data '{...}' \
  <?= Html::e($this->m_siteUrl) ?>/api/v1/email-tokens</pre>
</section>
<script type="module" src="/js/NewEmailTokenPage.js"></script>
<?php
    }
}
