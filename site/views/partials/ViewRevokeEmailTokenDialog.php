<?php

/*
 * The dialog that asks before an email token is revoked, opened by its page's revoke button (see
 * RevokeEmailTokenDialog.ts), which also loads how many of its emails are waiting in the queue, and would be cancelled.
 */

declare(strict_types = 1);


class ViewRevokeEmailTokenDialog extends Programster\AbstractView\AbstractView
{
    private readonly EmailToken $m_emailToken;


    /**
     * @param EmailToken $emailToken - the token it's for.
     */
    public function __construct(EmailToken $emailToken)
    {
        $this->m_emailToken = $emailToken;
    }


    protected function renderContent() : void
    {
        $tokenPath = '/email-tokens/' . rawurlencode((string)$this->m_emailToken->getId());
?>
<dialog id="revoke-email-token-dialog" aria-labelledby="revoke-email-token-title"
    data-queued-url="<?= Html::e("{$tokenPath}/queued-emails") ?>">
    <form method="post" action="<?= Html::e("{$tokenPath}/revoke") ?>" data-role="form">
        <?= new ViewCsrfField() ?>
        <h3 id="revoke-email-token-title">Revoke "<?= Html::e($this->m_emailToken->getName()) ?>"?</h3>
        <p>It stops working straight away, and any of its emails still in the queue are cancelled. This can't be
            undone.</p>
        <p data-role="queued" aria-live="polite"></p>
        <div class="form-actions">
            <button type="submit" class="danger" data-role="confirm">Revoke</button>
            <button type="button" class="secondary" data-role="cancel">Cancel</button>
        </div>
    </form>
</dialog>
<?php
    }
}
