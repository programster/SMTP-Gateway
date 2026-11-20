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
$html = $render('![The header](site/public/images/header.svg)', DocumentationPage::OVERVIEW);
$isShown = str_contains($html, 'src="/images/header.svg"') && str_contains($html, 'alt="The header"');
$suite->check('an image in site/public is shown from its address on this site', $isShown, $html);
$html = $render('![The header](../site/public/images/header.svg)', DocumentationPage::RATE_LIMITS);
$suite->check('as it is from a page in docs/', str_contains($html, 'src="/images/header.svg"'), $html);
$html = $render('![A diagram](diagram.svg) and ![a logo](/images/logo.svg)', DocumentationPage::RATE_LIMITS);
$isText = (!str_contains($html, 'diagram.svg') && str_contains($html, 'A diagram'));
$suite->check('any other image in the repository is just its alt text', $isText, $html);
$suite->check('an image at a path on this site is kept', str_contains($html, 'src="/images/logo.svg"'), $html);
$markdown = '![A chart](https://example.com/chart.svg) and ![a photo](//example.com/photo.jpg)';
$html = $render($markdown, DocumentationPage::OVERVIEW);
$isText = (!str_contains($html, '<img') && str_contains($html, 'A chart') && str_contains($html, 'a photo'));
$suite->check('an image on another site is just its alt text', $isText, $html);
$markdown = '[![Docker Hub](https://img.shields.io/docker/v/x/y)](https://hub.docker.com/r/x/y)';
$html = $render($markdown, DocumentationPage::OVERVIEW);
$link = '<a href="https://hub.docker.com/r/x/y">Docker Hub</a>';
$isLink = (!str_contains($html, '<img') && str_contains($html, $link));
$suite->check('so a linked badge is a link with its alt text', $isLink, $html);
$html = $render("# Rate limits and the queue\n\n## Per-token limits", DocumentationPage::RATE_LIMITS);
$suite->check('headings get GitHub\'s IDs', str_contains($html, 'id="per-token-limits"'), $html);
$html = $render('<script>alert(1)</script> <img src=x onerror=alert(1)>', DocumentationPage::OVERVIEW);
$suite->check('raw HTML is escaped', !str_contains($html, '<script') && !str_contains($html, '<img'), $html);
$html = $render('[click](javascript:alert)', DocumentationPage::OVERVIEW);
$suite->check('an unsafe link isn\'t made', !str_contains($html, 'javascript:'), $html);

// The headings listed beside a page: its sections, down to the level asked for, as plain text, in order.
$markdown = "# Page\n\n## One\n\n### `api_key` & more\n\n#### Deeper\n\n##### Deepest";
$html = $render($markdown, DocumentationPage::PROVIDERS);
$found = [];

foreach (DocumentationRenderer::findHeadings($html, 4) as $heading)
{
    /* @var $heading DocumentationHeading */
    $found[] = "{$heading->getLevel()}:{$heading->getId()}:{$heading->getText()}";
}

$expected = ['2:one:One', '3:apikey--more:api_key & more', '4:deeper:Deeper'];
$isFound = ($found === $expected);
$suite->check('a page\'s headings are found, down to a level, as plain text', $isFound, json_encode($found));
$page = (string)new ViewDocumentationPage(DocumentationRenderer::fetchPage(DocumentationPage::PROVIDERS));
$isListed = (str_contains($page, 'id="documentation-contents"') && str_contains($page, 'href="#sendgrid"'));
$suite->check('a page lists its sections beside it, down to each provider\'s', $isListed);
$hasCaret = str_contains($page, 'aria-controls="documentation-contents-sendgrid"')
    && str_contains($page, '<ul id="documentation-contents-sendgrid" data-role="subsections">');
$suite->check('with a caret to show or hide the sections within one', $hasCaret);
$hasNoCaret = !str_contains($page, 'aria-controls="documentation-contents-mailgun"');
$suite->check('but not for one with none', $hasNoCaret);

// The sections as a tree: each with those below its level, until the next at its level or above. A skipped level
// (an h4 straight after an h2) is nested under the heading above it.
$markdown = "# Page\n\n## One\n\n### Two\n\n#### Three\n\n## Four\n\n#### Five\n\n## Six";
$html = $render($markdown, DocumentationPage::PROVIDERS);

$describe = function (DocumentationSectionCollection $sections) use (&$describe) : string {
    $parts = [];

    foreach ($sections as $section)
    {
        /* @var $section DocumentationSection */
        $within = $section->getSubsections()->isEmpty() ? '' : '(' . $describe($section->getSubsections()) . ')';
        $parts[] = $section->getHeading()->getText() . $within;
    }

    return implode(' ', $parts);
};

$tree = $describe(DocumentationRenderer::findSections($html, 4));
$isNested = ($tree === 'One(Two(Three)) Four(Five) Six');
$suite->check('a page\'s sections are nested, even across a skipped level', $isNested, $tree);

// Every link to a section, on the same page or another, goes to a heading that's there, so restructuring a page
// can't leave a link going nowhere.
$idsBySlug = [];
$htmlBySlug = [];

foreach (DocumentationPage::cases() as $page)
{
    $html = DocumentationRenderer::fetchPage($page);
    preg_match_all('# id="([^"]+)"#', $html, $matches);
    $idsBySlug[$page->getSlug()] = $matches[1];
    $htmlBySlug[$page->getSlug()] = $html;
}

$broken = [];

foreach ($htmlBySlug as $slug => $html)
{
    preg_match_all('#href="(?:/documentation(?:/([a-z-]+))?)?\#([^"]+)"#', $html, $matches, PREG_SET_ORDER);

    foreach ($matches as $match)
    {
        $isOtherPage = str_starts_with($match[0], 'href="/documentation');
        // The overview's slug is empty, so /documentation#section is a section of the overview.
        $target = $isOtherPage ? ($match[1] ?? '') : $slug;

        if (!in_array(rawurldecode($match[2]), $idsBySlug[$target] ?? [], true))
        {
            $broken[] = "{$slug}: {$match[0]}";
        }
    }
}

$suite->check('every link to a section goes to one that\'s there', $broken === [], implode(', ', $broken));

$suite->finish();
