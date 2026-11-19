<?php

/*
 * The form for changing an email token's name and restrictions (see ViewEmailTokenFields). Its ID and secret can't be
 * changed, so whatever uses it carries on working.
 */

declare(strict_types = 1);


class ViewEditEmailTokenPage extends Programster\AbstractView\AbstractView
{
    private readonly EmailToken $m_emailToken;
    private readonly array $m_values;
    private readonly string $m_error;


    /**
     * @param EmailToken $emailToken - the token being changed.
     *
     * @param array<string, string> $values - the form's values, by field name: the token's as it is now (see
     * EmailTokenFormValues), or what was sent when it was invalid.
     *
     * @param string $error - why it was invalid, or an empty string.
     */
    public function __construct(EmailToken $emailToken, array $values, string $error)
    {
        $this->m_emailToken = $emailToken;
        $this->m_values = $values;
        $this->m_error = $error;
    }


    protected function renderContent() : void
    {
        $id = (string)$this->m_emailToken->getId();
        $tokenPath = '/email-tokens/' . rawurlencode($id);
?>
<h1>Edit the email token "<?= Html::e($this->m_emailToken->getName()) ?>"</h1>
<p><a href="<?= Html::e($tokenPath) ?>">Back to the email token</a></p>
<?= new ViewFormError($this->m_error) ?>
<form method="post" action="<?= Html::e("{$tokenPath}/edit") ?>" class="card" id="email-token-form">
    <?= new ViewCsrfField() ?>
    <p class="hint">Its ID (<code><?= Html::e($id) ?></code>) and secret stay the same, so whatever uses it carries
        on working, within these restrictions from its next request to the JSON API, or its next SMTP login (a
        connection that's already logged in keeps the ones it logged in with). Changed rate limits apply to its emails
        already in the queue too, but a changed priority only applies to emails queued from now on.</p>
    <?= new ViewEmailTokenFields($this->m_values, EmailTokenFormPurpose::EDITING) ?>
    <div class="form-actions"><button type="submit">Save</button>
        <a href="<?= Html::e($tokenPath) ?>">Cancel</a></div>
</form>
<script type="module" src="/js/EditEmailTokenPage.js"></script>
<?php
    }
}
