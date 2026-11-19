<?php

/*
 * The warning at the top of the dashboard when the service seems not to be seeing people's real IP addresses (see
 * ProxyWarning), saying what to set under Settings > Proxies, with a button to stop showing it in this browser, for a
 * service that really is reached directly inside a home or office network. Shows nothing when there's nothing to warn
 * about.
 */

declare(strict_types = 1);


class ViewProxyWarning extends Programster\AbstractView\AbstractView
{
    private readonly ProxyWarning $m_warning;


    public function __construct(ProxyWarning $warning)
    {
        $this->m_warning = $warning;
    }


    protected function renderContent() : void
    {
        $warning = $this->m_warning;
        $proxiesPath = Html::e(SettingsSection::PROXIES->getPagePath());
        $client = Html::e((string)$warning->getClientIp());
        $proxy = Html::e((string)$warning->getConnectionIp());

        $why =
            'Until the service sees your real address, the logs show the wrong addresses, IP address restrictions'
            . ' can\'t work, and the limit on failed logins counts everyone\'s failures against the proxy, so a few'
            . ' wrong passwords from anyone could stop everyone logging in for 15 minutes.';

        if ($warning->getKind() !== ProxyWarningKind::NONE)
        {
?>
<div class="flash flash-warning proxy-warning" role="alert">
<?php
            match ($warning->getKind()) {
                ProxyWarningKind::UNTRUSTED_PROXY => $this->renderUntrustedProxy($proxy, $proxiesPath),
                ProxyWarningKind::CLOUDFLARE => $this->renderCloudflare($proxiesPath),
                ProxyWarningKind::PRIVATE_ADDRESS => $this->renderPrivateAddress($client, $proxiesPath),
                ProxyWarningKind::NONE => null,
            };
?>
    <p><?= Html::e($why) ?></p>
    <form method="post" action="/proxy-warning/dismiss" class="inline-form">
        <?= new ViewCsrfField() ?>
        <button type="submit" class="secondary">Don't show this again</button>
        <span class="hint">In this browser, e.g. if the service really is reached directly, inside a home or office
            network.</span>
    </form>
</div>
<?php
        }
    }


    private function renderUntrustedProxy(string $proxy, string $proxiesPath) : void
    {
?>
    <p><strong>Your requests seem to come through a proxy the service doesn't trust,</strong> at
        <code><?= $proxy ?></code>: it says who it's forwarding for (with an X-Forwarded-For header), but as it isn't
        trusted, the service takes its address to be yours. If that's your reverse proxy or load balancer, add
        <code><?= $proxy ?></code> to the trusted proxies under <a href="<?= $proxiesPath ?>">Settings &gt;
        Proxies</a>.</p>
<?php
    }


    private function renderCloudflare(string $proxiesPath) : void
    {
?>
    <p><strong>Your requests seem to come through Cloudflare,</strong> but the site isn't set as being behind it, so
        the service takes Cloudflare's address to be yours. Switch on "Behind Cloudflare" under
        <a href="<?= $proxiesPath ?>">Settings &gt; Proxies</a>.</p>
<?php
    }


    private function renderPrivateAddress(string $client, string $proxiesPath) : void
    {
?>
    <p><strong>Your address seems to be <code><?= $client ?></code>, which is a private one,</strong> though your
        requests come through a proxy (they say who they're forwarding for). If there's more than one proxy in front
        of the service, each needs adding to the trusted proxies under <a href="<?= $proxiesPath ?>">Settings &gt;
        Proxies</a>. If you really are inside the same private network as the service, this is expected, and you
        can ignore it.</p>
<?php
    }
}
