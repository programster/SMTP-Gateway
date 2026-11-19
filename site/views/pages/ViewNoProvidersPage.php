<?php

/*
 * Shown on a fresh install, until the first provider is added: what providers are, and the editor, filled with an
 * example to change. Emails sent in the meantime are queued, and sent once a provider is added.
 */

declare(strict_types = 1);


class ViewNoProvidersPage extends Programster\AbstractView\AbstractView
{
    // What the editor starts with: a provider of each of the two most common types, to be changed.
    public const string EXAMPLE_YAML = <<<'YAML'
        # Add your providers below, and save. A setting written as "${NAME}" is read from the environment variable
        # NAME (e.g. set in .env, then restart), so secrets can be kept out of settings.yaml.
        #
        # mode: round_robin, overflow or fallback: how emails are shared between the providers.
        mode: "overflow"

        providers:
          - name: "sendgrid"
            type: "sendgrid"
            enabled: true
            api_key: "${SENDGRID_API_KEY}"
            rate_limits: []

          # Or an SMTP server of your own, e.g. mailcow:
          # - name: "mail-server"
          #   type: "custom"
          #   enabled: true
          #   host: "mail.example.org"
          #   port: 587
          #   security: "tls"
          #   username: "${MAIL_SERVER_USERNAME}"
          #   password: "${MAIL_SERVER_PASSWORD}"
          #   rate_limits: []
        YAML;

    private readonly string $m_yaml;
    private readonly string $m_version;
    private readonly string $m_error;


    /**
     * @param string $yaml - the YAML for the editor (the example, or what failed to save).
     *
     * @param string $version - the version it was based on: that of the missing providers section.
     *
     * @param string $error - why saving failed, or an empty string.
     */
    public function __construct(string $yaml, string $version, string $error)
    {
        $this->m_yaml = $yaml;
        $this->m_version = $version;
        $this->m_error = $error;
    }


    protected function renderContent() : void
    {
?>
<h1>Providers</h1>
<p class="flash flash-warning" role="status">No providers have been set up yet. Add your first one below. Until then,
    emails are accepted and queued, and they're sent once a provider has been added.</p>
<p>Every email is sent through a provider: a service such as SendGrid, Mailgun or Postmark, or an SMTP server of your
    own. You can add as many as you like, each with its own rate limits, and choose how emails are shared between them.
    They can also be set through the API.</p>
<p><a class="button" href="/providers/new">Add a provider</a></p>
<details class="card advanced"<?= ($this->m_error !== '') ? ' open' : '' ?>>
    <summary>Advanced: write the providers as YAML instead</summary>
    <?= new ViewProvidersEditor($this->m_yaml, $this->m_version, $this->m_error, ProvidersEditScope::SECTION) ?>
</details>
<?php
    }
}
