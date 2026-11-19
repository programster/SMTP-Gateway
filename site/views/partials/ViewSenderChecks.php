<?php

/*
 * Where the sender checks are shown (see SenderChecks): whether email from the allowed senders will be accepted
 * and trusted, and anything wrong with the providers' accounts. They take a few seconds, as they ask DNS and the
 * providers' APIs, so the page's script fetches them as JSON from $url once the page has loaded (see
 * SenderChecksPanel.ts).
 */

declare(strict_types = 1);


class ViewSenderChecks extends Programster\AbstractView\AbstractView
{
    private readonly string $m_url;
    private readonly string $m_intro;


    /**
     * @param string $url - the JSON endpoint to fetch the checks from, e.g. "/email-tokens/<id>/sender-checks".
     *
     * @param string $intro - what's being checked, as plain text.
     */
    public function __construct(string $url, string $intro)
    {
        $this->m_url = $url;
        $this->m_intro = $intro;
    }


    protected function renderContent() : void
    {
?>
<section class="card sender-checks" id="sender-checks" data-url="<?= Html::e($this->m_url) ?>"
    data-csrf-token="<?= Html::e(WebSession::getCsrfToken()) ?>">
    <h2>Sender checks</h2>
    <p class="hint"><?= Html::e($this->m_intro) ?></p>
    <div data-sender-check-results><p class="muted">Checking DNS and the providers&hellip;</p></div>
    <button type="button" class="secondary" data-sender-check-again>Check again</button>
</section>
<?php
    }
}
