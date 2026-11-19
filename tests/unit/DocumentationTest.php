<?php

/*
 * The documentation shown in the web interface: that every page has its file, and every file in docs/ is a page (so
 * neither can be added without the other), and that the Markdown is rendered safely, with its links pointing at the
 * pages in the web interface.
 */

declare(strict_types = 1);

require_once __DIR__ . '/TestSuite.php';

$suite = new TestSuite('Documentation');
$root = DocumentationPage::DOCS_ROOT;
$files = [];

foreach (DocumentationPage::cases() as $page)
{
    $files[] = $page->getFile();
    $suite->check("{$page->getTitle()} has its file", is_readable("{$root}/{$page->getFile()}"), $page->getFile());
    $html = DocumentationRenderer::fetchPage($page);
    $suite->check("{$page->getTitle()} renders, with a heading", str_contains($html, '<h1'), substr($html, 0, 80));
}

foreach (glob("{$root}/docs/*.md") as $path)
{
    $file = 'docs/' . basename($path);
    $isListed = ($file === 'docs/README.md' || in_array($file, $files, true));
    $suite->check("{$file} is a page of the documentation", $isListed);
}

$render = function (string $markdown, DocumentationPage $page) : string {
    return DocumentationRenderer::render($markdown, $page);
};

$markdown = 'See [Disk space](retention.md#disk-space) and [the index](README.md).';
$html = $render($markdown, DocumentationPage::RATE_LIMITS);
$isLinked = str_contains($html, 'href="/documentation/retention#disk-space"');
$suite->check('a link to another page goes to it', $isLinked, $html);
$suite->check('and the index goes to the overview', str_contains($html, 'href="/documentation"'), $html);
$html = $render('See [Rate limits](docs/rate-limits.md) and [back](#top).', DocumentationPage::OVERVIEW);
$isLinked = str_contains($html, 'href="/documentation/rate-limits"');
$suite->check('a link from the README goes to the page', $isLinked, $html);
$suite->check('a link to a section of the same page is kept', str_contains($html, 'href="#top"'), $html);
$markdown = 'See [`.env.example`](../.env.example), or [the API](/openapi.json).';
$html = $render($markdown, DocumentationPage::CONFIGURATION);
$isText = (!str_contains($html, '.env.example"') && str_contains($html, '.env.example'));
$suite->check('a link to another file is just its text', $isText, $html);
$suite->check('a path on this site is kept', str_contains($html, 'href="/openapi.json"'), $html);
$html = $render("# Rate limits and the queue\n\n## Per-token limits", DocumentationPage::RATE_LIMITS);
$suite->check('headings get GitHub\'s IDs', str_contains($html, 'id="per-token-limits"'), $html);
$html = $render('<script>alert(1)</script> <img src=x onerror=alert(1)>', DocumentationPage::OVERVIEW);
$suite->check('raw HTML is escaped', !str_contains($html, '<script') && !str_contains($html, '<img'), $html);
$html = $render('[click](javascript:alert)', DocumentationPage::OVERVIEW);
$suite->check('an unsafe link isn\'t made', !str_contains($html, 'javascript:'), $html);

$suite->finish();
