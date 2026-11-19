<?php

/*
 * Shown once, straight after an API key is created: the only time the key is ever available.
 */

declare(strict_types = 1);


class ViewApiKeyCreatedPage extends Programster\AbstractView\AbstractView
{
    private readonly CreatedApiKey $m_created;
    private readonly string $m_siteUrl;


    /**
     * @param CreatedApiKey $created - the API key, with its secret, which is only shown now.
     *
     * @param string $siteUrl - the address the site is reached at, for the example (e.g. "https://smtp.example.org").
     */
    public function __construct(CreatedApiKey $created, string $siteUrl)
    {
        $this->m_created = $created;
        $this->m_siteUrl = $siteUrl;
    }


    protected function renderContent() : void
    {
        $token = $this->m_created->getToken()->getPlaintext();
?>
<h1>API key created</h1>
<p class="flash flash-error">Copy the key now. It can't be shown again, as only a hash of its secret is stored.</p>
<div class="card" id="created-secret">
    <label for="api-key">API key for "<?= Html::e($this->m_created->getApiKey()->getName()) ?>"</label>
    <div class="secret-box"><input type="text" id="api-key" value="<?= Html::e($token) ?>" readonly><button
        type="button" class="secondary" data-copy-target="api-key">Copy</button></div>
    <p class="hint">Its first <?= Token::ID_LENGTH ?> characters,
        <code><?= Html::e($this->m_created->getApiKey()->getId()) ?></code>, are its ID, which is shown on the pages and
        in the logs. The rest is its secret.</p>
    <p>Send it with each request to the management API:</p>
    <pre>curl \
  --header 'Authorization: Bearer <?= Html::e($token) ?>' \
  <?= Html::e($this->m_siteUrl) ?>/api/v1/email-tokens</pre>
    <p><a href="/api-keys">Done, I've copied the key</a></p>
</div>
<script type="module" src="/js/SecretCreatedPage.js"></script>
<?php
    }
}
