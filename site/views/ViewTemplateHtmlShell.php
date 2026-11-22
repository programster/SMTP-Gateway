<?php

/*
 * The HTML shell every page is wrapped in: the <head>, the side panel with the menu (once logged in, see ViewSidebar),
 * or a plain header with the site's name (before), any flash message, and the footer. It knows nothing about any
 * page's JavaScript; each page includes its own.
 */

declare(strict_types = 1);

use Programster\AbstractView\AbstractView;


class ViewTemplateHtmlShell extends AbstractView
{
    private readonly string $m_title;
    private readonly AbstractView $m_page;
    private readonly bool $m_isLoggedIn;
    private readonly FlashMessage $m_flash;


    /**
     * @param string $title - the page's title.
     *
     * @param AbstractView $page - the page to wrap.
     *
     * @param bool $isLoggedIn - whether someone is logged in, so gets the side panel rather than the plain header.
     *
     * @param FlashMessage $flash - the message to show above the page, which may be empty.
     */
    public function __construct(string $title, AbstractView $page, bool $isLoggedIn, FlashMessage $flash)
    {
        $this->m_title = $title;
        $this->m_page = $page;
        $this->m_isLoggedIn = $isLoggedIn;
        $this->m_flash = $flash;
    }


    protected function renderContent() : void
    {
        $isLoggedIn = $this->m_isLoggedIn;
        $flash = $this->m_flash;
        $requestPath = parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
        $requestPath = is_string($requestPath) ? $requestPath : '/';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= Html::e($this->m_title) ?> - <?= Html::e(SERVICE_NAME) ?></title>
    <link rel="stylesheet" href="/css/style.css">
</head>
<body class="<?= $isLoggedIn ? 'has-sidebar' : '' ?>">
    <?php if ($isLoggedIn): ?>
        <?= new ViewSidebar($requestPath) ?>
    <?php else: ?>
        <header class="site-header">
            <a class="site-name" href="/"><?= Html::e(SERVICE_NAME) ?></a>
        </header>
    <?php endif; ?>
    <div class="page">
    <main>
        <?php if ($flash->isEmpty() === false): ?>
            <p
                class="flash <?= $flash->isError() ? 'flash-error' : 'flash-success' ?>"><?= Html::e(
                    $flash->getMessage()
                ) ?></p>
        <?php endif; ?>
        <?= $this->m_page ?>
    </main>
    <footer class="site-footer">
        <?= Html::e(SERVICE_NAME) ?> &middot; <a href="/docs">API documentation</a> &middot; <a
            href="/openapi.json">openapi.json</a>
    </footer>
    </div>
</body>
</html>
<?php
    }
}
