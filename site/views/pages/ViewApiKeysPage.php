<?php

/*
 * The management API's API keys, on two tabs: the active ones (the default), and the revoked ones, with when each was
 * revoked and how old it was then. Below them is the form for creating one.
 */

declare(strict_types = 1);


class ViewApiKeysPage extends Programster\AbstractView\AbstractView
{
    private readonly ApiKeyCollection $m_active;
    private readonly ApiKeyCollection $m_revoked;

    /** @var array<string, string[]> */
    private readonly array $m_allowedNetworks;

    private readonly bool $m_isShowingRevoked;
    private readonly array $m_values;
    private readonly string $m_error;


    /**
     * @param ApiKeyCollection $apiKeys - every API key, active and revoked.
     *
     * @param array<string, string[]> $allowedNetworks - each API key's ID, to the networks it can be used from (see
     * ApiKeyRepository::loadAllowedNetworkStrings()).
     *
     * @param bool $isShowingRevoked - whether the Revoked tab is the one open.
     *
     * @param array<string, string> $values - the form's values: "name", and the allowed IP addresses' rows,
     * "allowed_ips_0" and so on (see ViewValueRows).
     *
     * @param string $error - why the form was invalid, or an empty string.
     */
    public function __construct(
        ApiKeyCollection $apiKeys,
        array $allowedNetworks,
        bool $isShowingRevoked,
        array $values,
        string $error
    )
    {
        $active = [];
        $revoked = [];

        foreach ($apiKeys as $apiKey)
        {
            /* @var $apiKey ApiKey */
            if ($apiKey->isRevoked())
            {
                $revoked[] = $apiKey;
            }
            else
            {
                $active[] = $apiKey;
            }
        }

        $this->m_active = new ApiKeyCollection(...$active);
        $this->m_revoked = new ApiKeyCollection(...$revoked);
        $this->m_allowedNetworks = $allowedNetworks;
        $this->m_isShowingRevoked = $isShowingRevoked;
        $this->m_values = $values;
        $this->m_error = $error;
    }


    protected function renderContent() : void
    {
        $getField = function (string $name) : string {
            return (string)($this->m_values[$name] ?? '');
        };

        $ipValues = array_values(PolicyInput::readValueRowsFromForm($getField, 'allowed_ips'));
        $ipCheck = ValueRowCheck::IP_NETWORK;
        $ipRows = new ViewValueRows('allowed_ips', 'Allowed IP address', $ipCheck, '10.0.0.0/8', ...$ipValues);
?>
<h1>API keys</h1>
<p>An API key lets a program use the management API (<code>/api/v1</code>) to list, create and revoke email tokens,
    change the rate limits and read the statistics, by sending <code>Authorization: Bearer &lt;API key&gt;</code>. Each
    key can only be used from the IP addresses you give it. Only a hash of each key's secret is stored, so a key can't
    be shown again: if one is lost, create a new one and revoke the old one. See the
    <a href="/docs">API documentation</a>.</p>

<?= new ViewListTabs(
    '/api-keys',
    'Revoked',
    'revoked',
    count($this->m_active),
    count($this->m_revoked),
    $this->m_isShowingRevoked
) ?>
<?php if ($this->m_isShowingRevoked): ?>
    <?php $this->renderRevoked(); ?>
<?php else: ?>
    <?php $this->renderActive(); ?>
<?php endif; ?>

<h2>Create an API key</h2>
<?= new ViewFormError($this->m_error) ?>
<form method="post" action="/api-keys" class="card">
    <?= new ViewCsrfField() ?>
    <label for="name">Name</label>
    <input type="text" id="name" name="name" value="<?= Html::e((string)($this->m_values['name'] ?? '')) ?>"
        placeholder="e.g. Deployment scripts" required maxlength="255">
    <h3>Allowed IP addresses</h3>
    <?= $ipRows ?>
    <p class="hint">One per row: a single address (IPv4 or IPv6), or a CIDR range. Filled in with the address you're
        using now. To allow anywhere, add <code>0.0.0.0/0</code> and <code>::/0</code>.</p>
    <div class="form-actions"><button type="submit">Create the API key</button></div>
</form>
<script type="module" src="/js/ApiKeysPage.js"></script>
<?php
    }


    private function renderActive() : void
    {
?>
<?php if (count($this->m_active) === 0): ?>
<p class="muted">No active API keys.</p>
<?php else: ?>
<div class="table-wrap">
<table>
    <thead><tr><th>Name</th><th>ID</th><th>Created</th><th>Age</th><th>Last used</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($this->m_active as $apiKey): ?>
        <?php /* @var $apiKey ApiKey */ ?>
        <tr>
            <td><?php $this->renderName($apiKey); ?></td>
            <td class="mono"><?= Html::e($apiKey->getId()) ?></td>
            <td><?= Html::e(Time::format($apiKey->getCreatedAt())) ?></td>
            <td><?= new ViewKeyAge($apiKey->getCreatedAt()) ?></td>
            <td><?= new ViewLastUse($apiKey->getLastUsedAt(), $apiKey->getLastUsedIp()) ?></td>
            <td>
                <form method="post" action="/api-keys/<?= Html::e($apiKey->getId()) ?>/revoke" class="inline-form">
                    <?= new ViewCsrfField() ?>
                    <button type="submit" class="danger">Revoke</button>
                </form>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
<?php endif; ?>
<?php
    }


    private function renderRevoked() : void
    {
?>
<?php if (count($this->m_revoked) === 0): ?>
<p class="muted">No revoked API keys.</p>
<?php else: ?>
<div class="table-wrap">
<table>
    <thead><tr><th>Name</th><th>ID</th><th>Created</th><th>Revoked</th><th>Age when revoked</th><th>Last used</th></tr>
    </thead>
    <tbody>
    <?php foreach ($this->m_revoked as $apiKey): ?>
        <?php /* @var $apiKey ApiKey */ $revokedAt = (int)$apiKey->getRevokedAt(); ?>
        <tr>
            <td><?php $this->renderName($apiKey); ?></td>
            <td class="mono"><?= Html::e($apiKey->getId()) ?></td>
            <td><?= Html::e(Time::format($apiKey->getCreatedAt())) ?></td>
            <td><?= Html::e(Time::format($revokedAt)) ?></td>
            <td><?= new ViewRevokedAge($apiKey->getCreatedAt(), $revokedAt) ?></td>
            <td><?= new ViewLastUse($apiKey->getLastUsedAt(), $apiKey->getLastUsedIp()) ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
<?php endif; ?>
<?php
    }


    // A key's name, with the IP addresses it can be used from under it.
    private function renderName(ApiKey $apiKey) : void
    {
        $networks = $this->m_allowedNetworks[$apiKey->getId()] ?? [];
?>
<?= Html::e($apiKey->getName()) ?><br><span class="hint mono"><?= Html::e(implode(', ', $networks)) ?></span>
<?php
    }
}
