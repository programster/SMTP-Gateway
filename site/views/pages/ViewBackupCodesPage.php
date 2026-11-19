<?php

/*
 * Shown once, straight after an account's backup codes are generated: the only time they're ever available, as only
 * their hashes are stored. They can be printed (on a page of their own, centred), downloaded as a text file or copied.
 * The codes are in Atkinson Hyperlegible Mono, so they're easy to copy by hand: its zero is slashed, and its capital I,
 * lowercase l and 1 all look different (though the codes never use 0, o, 1, l or i anyway).
 */

declare(strict_types = 1);


class ViewBackupCodesPage extends Programster\AbstractView\AbstractView
{
    private readonly string $m_siteUrl;
    private readonly string $m_email;

    /** @var list<string> */
    private readonly array $m_codes;


    /**
     * @param string $siteUrl - the address the site is reached at (e.g. "https://smtp.example.org"), which the title,
     * printout and download say the codes are for.
     *
     * @param string $email - the account's email address, which the printout and download are labelled with.
     *
     * @param string ...$codes - the codes, as they're shown (e.g. "k7m2p-x9q4r").
     */
    public function __construct(string $siteUrl, string $email, string ...$codes)
    {
        $this->m_siteUrl = $siteUrl;
        $this->m_email = $email;
        $this->m_codes = array_values($codes);
    }


    protected function renderContent() : void
    {
        $count = count($this->m_codes);
?>
<div class="backup-codes-page" id="backup-codes"
     data-email="<?= Html::e($this->m_email) ?>"
     data-site-url="<?= Html::e($this->m_siteUrl) ?>">
    <h1>Your backup codes for <span class="backup-codes-site"><?= Html::e($this->m_siteUrl) ?></span></h1>
    <p class="flash flash-error">Save these now. They can't be shown again, as only a hash of each is stored.</p>
    <p class="print-only backup-codes-for">For <?= Html::e($this->m_email) ?>, generated
        <?= Html::e(Time::format(Time::now())) ?></p>
    <div class="card backup-codes-card">
        <p class="no-print">If you lose your authenticator app or security key, you can log in with one of these
            instead. Each one works once. Keep them somewhere safe, away from your devices, e.g. printed out, or in a
            password manager.</p>
        <ol class="backup-codes" data-role="codes">
            <?php foreach ($this->m_codes as $code): ?>
                <li><code><?= Html::e($code) ?></code></li>
            <?php endforeach; ?>
        </ol>
        <p class="print-only">Each code works once, instead of your authenticator app or security key, when you log
            in. Keep this somewhere safe.</p>
        <div class="form-actions no-print">
            <button type="button" data-role="print">Print</button>
            <button type="button" class="secondary" data-role="download">Download</button>
            <button type="button" class="secondary" data-role="copy">Copy</button>
        </div>
        <p class="hint no-print">These <?= $count ?> codes replace any you had before, which no longer work. You can
            generate new ones from <a href="/account">My account</a> at any time, e.g. once you've used most of these,
            or if someone else may have seen them.</p>
        <p class="no-print"><a class="button" href="/account">Done, I've saved them</a></p>
    </div>
</div>
<script type="module" src="/js/BackupCodesPage.js"></script>
<?php
    }
}
