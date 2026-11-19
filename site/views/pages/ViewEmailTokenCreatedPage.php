<?php

/*
 * Shown once, straight after an email token is created: the only time the token is ever available.
 */

declare(strict_types = 1);


class ViewEmailTokenCreatedPage extends Programster\AbstractView\AbstractView
{
    private readonly CreatedEmailToken $m_created;
    private readonly string $m_smtpHostname;
    private readonly string $m_siteUrl;


    public function __construct(CreatedEmailToken $created, string $smtpHostname, string $siteUrl)
    {
        $this->m_created = $created;
        $this->m_smtpHostname = $smtpHostname;
        $this->m_siteUrl = $siteUrl;
    }


    protected function renderContent() : void
    {
        $emailToken = $this->m_created->getEmailToken();
        $token = $this->m_created->getToken()->getPlaintext();
        $pagePath = "/email-tokens/{$emailToken->getId()}";
?>
<h1>Email token created</h1>
<p class="flash flash-error">Copy the token now. It can't be shown again, as only a hash of its secret is stored.</p>
<div class="card" id="created-secret">
    <h2>The token for "<?= Html::e($emailToken->getName()) ?>"</h2>
    <label for="email-token">Email token</label>
    <div class="secret-box"><input type="text" id="email-token" value="<?= Html::e($token) ?>" readonly><button
        type="button" class="secondary" data-copy-target="email-token">Copy</button></div>
    <p class="hint">Its first <?= Token::ID_LENGTH ?> characters, <code><?= Html::e($emailToken->getId()) ?></code>,
        are its ID, which is shown on the pages and in the logs. The rest is its secret.</p>

    <h3>Sending through SMTP</h3>
    <p>Point the service's SMTP settings at this server. The connection is always encrypted:</p>
    <ul>
        <li>Host: <code><?= Html::e($this->m_smtpHostname) ?></code></li>
        <li>Port <code>465</code> with SSL/TLS (implicit TLS), or port <code>587</code> with STARTTLS</li>
        <li>Username: the token's ID, <code><?= Html::e($emailToken->getId()) ?></code>. Password: the whole token.</li>
    </ul>
    <p class="hint">The reply to each email says <code>250 2.0.0 Ok: sent as &lt;id&gt;</code> if it was sent straight
        away, or <code>250 2.0.0 Ok: queued as &lt;id&gt;</code> if a rate limit meant it was queued. Either way it was
        accepted, and will be sent.</p>

    <h3>Sending through the API</h3>
    <pre>curl \
  --header 'Authorization: Bearer <?= Html::e($token) ?>' \
  --header 'Content-Type: application/json' \
  --data '{
    "from": {"email": "noreply@example.org"},
    "to": [{"email": "someone@example.org"}],
    "subject": "Hello",
    "text": "Hello from the API"
  }' \
  <?= Html::e($this->m_siteUrl) ?>/api/v1/send</pre>
    <p class="hint">See the <a href="/docs">API documentation</a> for every option.</p>
    <p><a href="<?= Html::e($pagePath) ?>">Done, I've copied the token</a></p>
</div>
<?= new ViewSenderChecks("{$pagePath}/sender-checks", ViewEmailTokenPage::SENDER_CHECKS_INTRO) ?>
<script type="module" src="/js/EmailTokenCreatedPage.js"></script>
<?php
    }
}
