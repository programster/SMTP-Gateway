<?php

/*
 * The side panel, shown once logged in: the site's name, the menu, and the account links at the bottom. A section
 * of the menu (e.g. "Admin") opens to show its links when it's clicked. It's a <details> element, so this works without
 * any JavaScript. The section holding the page being shown starts open, and that page's link is marked as current.
 */

declare(strict_types = 1);


class ViewSidebar extends Programster\AbstractView\AbstractView
{
    private readonly string $m_requestPath;


    /**
     * @param string $requestPath - the path of the page being shown, e.g. "/messages/123", to mark it in the menu.
     */
    public function __construct(string $requestPath)
    {
        $this->m_requestPath = $requestPath;
    }


    /**
     * Create the menu. Its links are the site's pages, grouped into sections. The settings have a page for each
     * section of settings.yaml (including the providers, which have a page of their own), and Server resources. The
     * documentation has a page for the README and each page in docs/, and the API's reference.
     *
     * @return NavEntryCollection
     */
    public static function createMenu() : NavEntryCollection
    {
        $emails = new NavLink('Emails', '/messages');
        $rejections = new NavLink('Rejections', '/rejections');
        $apiKeys = new NavLink('API keys', '/api-keys');
        $emailTokens = new NavLink('Email tokens', '/email-tokens');
        $users = new NavLink('Users', '/admin/users');
        $settingsLinks = [];

        // The providers come straight after General, as they're the settings most often changed.
        foreach (SettingsSection::cases() as $section)
        {
            // A section shown as part of another's page (e.g. the sending settings, on the General page) has no link.
            if ($section !== SettingsSection::PROVIDERS && $section->getPageSection() === $section)
            {
                $settingsLinks[] = new NavLink($section->getTitle(), $section->getPagePath());
            }

            if ($section === SettingsSection::GENERAL)
            {
                $providers = SettingsSection::PROVIDERS;
                $settingsLinks[] = new NavLink($providers->getTitle(), $providers->getPagePath());
            }
        }

        $settingsLinks[] = new NavLink('Server resources', SettingsController::SERVER_RESOURCES_PATH);

        $documentationLinks = [];

        foreach (DocumentationPage::cases() as $page)
        {
            // The overview's path starts every other page's, so it only matches its own.
            $documentationLinks[] = new NavLink($page->getTitle(), $page->getPagePath(), NavMatch::PAGE_ONLY);
        }

        $documentationLinks[] = new NavLink('API reference', '/docs');

        $below = NavMatch::PAGE_AND_BELOW;

        return new NavEntryCollection(
            new NavLink('Dashboard', '/', $below, Icon::DASHBOARD),
            new NavSection('Activity', Icon::ACTIVITY, $emails, $rejections),
            new NavLink('Global rate limits', '/rate-limits', $below, Icon::RATE_LIMITS),
            new NavSection('Access', Icon::ACCESS, $apiKeys, $emailTokens, $users),
            new NavLink('Logs', '/admin/logs', $below, Icon::LOGS),
            new NavSection('Settings', Icon::SETTINGS, ...$settingsLinks),
            new NavSection('Documentation', Icon::DOCUMENTATION, ...$documentationLinks)
        );
    }


    protected function renderContent() : void
    {
        $account = new NavLink('My account', '/account', NavMatch::PAGE_AND_BELOW, Icon::ACCOUNT);
?>
<aside class="sidebar">
    <a class="site-name" href="/"><?= Html::e(SERVICE_NAME) ?></a>
    <nav class="sidebar-menu" aria-label="Main">
        <ul>
        <?php foreach (self::createMenu() as $entry): ?>
            <li>
            <?php if ($entry instanceof NavSection): ?>
                <details class="nav-section"<?= $entry->isCurrentFor($this->m_requestPath) ? ' open' : '' ?>>
                    <summary><?= new ViewIcon($entry->getIcon()) ?><span><?= Html::e(
                        $entry->getLabel()
                    ) ?></span></summary>
                    <ul>
                    <?php foreach ($entry->getLinks() as $link): ?>
                        <li><?php $this->renderLink($link); ?></li>
                    <?php endforeach; ?>
                    </ul>
                </details>
            <?php elseif ($entry instanceof NavLink): ?>
                <?php $this->renderLink($entry); ?>
            <?php endif; ?>
            </li>
        <?php endforeach; ?>
        </ul>
    </nav>
    <div class="sidebar-account">
        <?php $this->renderLink($account); ?>
        <form method="post" action="/logout">
            <?= new ViewCsrfField() ?>
            <button type="submit" class="nav-link"><?= new ViewIcon(Icon::LOG_OUT) ?><span>Log
                out</span></button>
        </form>
    </div>
</aside>
<?php
    }


    private function renderLink(NavLink $link) : void
    {
        $current = $link->isCurrentFor($this->m_requestPath) ? ' aria-current="page"' : '';
?>
<a class="nav-link" href="<?= Html::e($link->getPath()) ?>"<?= $current ?>><?= new ViewIcon(
    $link->getIcon()
) ?><span><?= Html::e($link->getLabel()) ?></span></a>
<?php
    }
}
