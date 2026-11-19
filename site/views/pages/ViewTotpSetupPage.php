<?php

/*
 * Setting up an authenticator app: scan the QR code (or type the secret), then give a code to prove it worked.
 */

declare(strict_types = 1);


class ViewTotpSetupPage extends Programster\AbstractView\AbstractView
{
    private readonly string $m_qrCodeDataUri;
    private readonly string $m_secret;
    private readonly string $m_error;


    public function __construct(string $qrCodeDataUri, string $secret, string $error)
    {
        $this->m_qrCodeDataUri = $qrCodeDataUri;
        $this->m_secret = $secret;
        $this->m_error = $error;
    }


    protected function renderContent() : void
    {
?>
<div class="card narrow">
    <h1>Set up an authenticator app</h1>
    <?= new ViewFormError($this->m_error) ?>
    <p>Scan this with your authenticator app (e.g. Google Authenticator, Aegis, 1Password):</p>
    <img class="qr" src="<?= Html::e($this->m_qrCodeDataUri) ?>" alt="QR code for setting up an authenticator app">
    <p class="hint">Or enter this secret by hand:
        <code><?= Html::e(trim(chunk_split($this->m_secret, 4, ' '))) ?></code></p>
    <form method="post" action="/account/totp">
        <?= new ViewCsrfField() ?>
        <label for="code">Then enter the 6-digit code it shows</label>
        <input type="text" id="code" name="code" inputmode="numeric" pattern="[0-9 ]{6,7}" autocomplete="one-time-code"
            required autofocus>
        <div class="form-actions"><button type="submit">Turn on</button> <a href="/account">Cancel</a></div>
    </form>
</div>
<?php
    }
}
