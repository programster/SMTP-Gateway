<?php

/*
 * The read-only Server resources page, under Settings: the server's memory and vCPUs, where PostgreSQL runs,
 * the .env variables that limit the memory and CPU the service uses, with two suggested values for each, and any
 * warnings about values that don't fit together. Nothing here is applied: the values go in .env, by hand.
 */

declare(strict_types = 1);


class ViewServerResourcesPage extends Programster\AbstractView\AbstractView
{
    private readonly ResourceAdvisor $m_advisor;


    public function __construct(ResourceAdvisor $advisor)
    {
        $this->m_advisor = $advisor;
    }


    protected function renderContent() : void
    {
        $advisor = $this->m_advisor;
        $server = $advisor->getServer();
        $total = ResourceVariable::describeBytes($server->getTotalMemoryBytes());
        $os = ResourceVariable::describeBytes($advisor->getOsReserveBytes());
        $database = ResourceVariable::describeBytes($advisor->getDatabaseMemoryBytes());
        $app = ResourceVariable::describeBytes(max(0, $advisor->getAppMemoryBytes()));
        $containerLimit = ResourceVariable::describeBytes($server->getContainerMemoryLimitBytes());
?>
<h1>Server resources</h1>
<div class="card">
    <p>These limits are set in <code>.env</code>, and apply when the containers are restarted. When one isn't set,
        its default suits a VPS with 1 GB of memory and 2 vCPUs. The suggestions are for this server, and assume the
        provided compose file. Nothing here is changed for you.</p>
    <p><strong>This server:</strong> <?= Html::e($total) ?> of memory and <?= $server->getVcpus() ?> vCPUs.
        Of the memory, about <?= Html::e($os) ?> is kept for the operating system,
        <?= Html::e($database) ?> for PostgreSQL, and <?= Html::e($app) ?> is left for the app.
        <?php if ($server->hasContainerMemoryLimit()): ?>
            The app's container is limited to <?= Html::e($containerLimit) ?>.
        <?php endif; ?>
    </p>
    <p><?= Html::e($advisor->getLocation()->describe(DB_HOST)) ?></p>
    <?php foreach ($advisor->getWarnings() as $warning): ?>
        <p class="flash flash-warning" role="alert"><?= Html::e($warning) ?></p>
    <?php endforeach; ?>
    <p>About <?= $advisor->getLargeEmailsAtOnce() ?> emails at the size limit can be handled at once, when little
        else is running, before the app runs out of memory. Lowering <code>SMTP_MAX_MESSAGE_BYTES</code> to the
        largest email your services really send allows more.</p>
    <p class="hint">"If you change everything" assumes every variable is changed to its suggestion. "If you only
        change this" keeps every other variable as it is now, so if you need one larger than suggested, the others
        show what they should be with it. A highlighted value differs from the current one.</p>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Variable</th>
                    <th>Now</th>
                    <th>If you change everything</th>
                    <th>If you only change this</th>
                    <th>How it's worked out</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($advisor->getSuggestions() as $suggestion): ?>
                <?php $this->renderRow($suggestion); ?>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <h3>The suggestions, if you change everything</h3>
    <pre id="suggested-env"><?= Html::e($advisor->getEnvLines()) ?></pre>
</div>
<?php
    }


    private function renderRow(ResourceSuggestion $suggestion) : void
    {
        $variable = $suggestion->getVariable();
        $current = $suggestion->getCurrent();
        $everything = $suggestion->getWithEverythingChanged();
        $onlyThis = $suggestion->getWithOnlyThisChanged();
?>
<tr>
    <td><code><?= Html::e($variable->value) ?></code>
        <div class="hint"><?= Html::e($variable->getDescription()) ?></div></td>
    <td><?= Html::e($variable->describeValue($current)) ?></td>
    <td<?= $this->getDiffersClass($current, $everything) ?>><?= Html::e($variable->formatValue($everything)) ?></td>
    <td<?= $this->getDiffersClass($current, $onlyThis) ?>><?= Html::e($variable->formatValue($onlyThis)) ?></td>
    <td class="hint"><?= Html::e($variable->getHowSuggested()) ?></td>
</tr>
<?php
    }


    private function getDiffersClass(int $current, int $suggested) : string
    {
        return ($current === $suggested) ? '' : ' class="differs"';
    }
}
