<?php

declare(strict_types = 1);


class ViewRateLimitsPage extends Programster\AbstractView\AbstractView
{
    private readonly RateLimitSet $m_limits;
    private readonly string $m_error;


    public function __construct(RateLimitSet $limits, string $error)
    {
        $this->m_limits = $limits;
        $this->m_error = $error;
    }


    protected function renderContent() : void
    {
?>
<h1>Global rate limits</h1>
<p>These limit everything sent through the provider, across every token, e.g. to stay within your provider's plan. Each
    can limit the emails handed to the provider (each is one request, however many recipients it has), or the recipients
    (To, Cc and Bcc alike), in any mix: e.g. 10 emails per second, and 50,000 recipients per month. Each token can also
    have its own limits, which apply on top of these. There are no limits until you add some.</p>
<p>An email that would go over a limit isn't refused. It's queued, and sent as soon as every limit allows. The client is
    told which happened: the SMTP reply says <code>sent as &lt;id&gt;</code> or <code>queued as &lt;id&gt;</code>, and
    the API responds <code>201</code> (sent) or <code>202</code> (queued).</p>
<p>Currently: <strong><?= Html::e($this->m_limits->describe()) ?></strong></p>
<?= new ViewFormError($this->m_error) ?>
<form method="post" action="/rate-limits" class="card">
    <?= new ViewCsrfField() ?>
    <?= new ViewRateLimitFields($this->m_limits->getLimits()) ?>
    <p class="hint">Leave a row's number empty to remove it. Leave them all empty for no global limit. A month is a
        sliding 30 days.</p>
    <div class="form-actions"><button type="submit">Save</button></div>
</form>
<script type="module" src="/js/RateLimitsPage.js"></script>
<?php
    }
}
