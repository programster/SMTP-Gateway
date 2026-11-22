<?php

/*
 * Turns the documentation's Markdown into HTML for the web interface, with league/commonmark (GitHub-flavoured, for
 * the tables). Any raw HTML in the Markdown is escaped, and unsafe links (e.g. javascript:) are dropped, so a page
 * can't be used to inject anything. Headings get GitHub's IDs, so links to a section work as they do on GitHub.
 *
 * Links between the docs are made to point at their pages in the web interface. A link to any other file in the
 * repository (e.g. .env.example) becomes plain text, as the web interface doesn't serve those. An image in site/public
 * (e.g. the README's header) is shown from its address on this site. Any other image becomes its alt text, whether
 * it's another file in the repository, or on another site (e.g. the README's badges), which the Content Security
 * Policy wouldn't let the page show, and which would tell that site who's reading the docs.
 */

declare(strict_types = 1);

use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\GithubFlavoredMarkdownExtension;
use League\CommonMark\Extension\HeadingPermalink\HeadingPermalinkExtension;
use League\CommonMark\MarkdownConverter;


final class DocumentationRenderer
{
    /**
     * Render one of the documentation's pages.
     *
     * @param DocumentationPage $page - the page.
     *
     * @return string - its HTML.
     *
     * @throws RuntimeException - if its file can't be read.
     */
    public static function fetchPage(DocumentationPage $page) : string
    {
        $path = DocumentationPage::DOCS_ROOT . '/' . $page->getFile();
        $markdown = is_readable($path) ? file_get_contents($path) : false;

        if ($markdown === false)
        {
            throw new RuntimeException("The documentation page {$page->getFile()} couldn't be read from {$path}.");
        }

        return self::render($markdown, $page);
    }


    /**
     * Find the headings of a rendered page, from its sections (level 2) down to a given level, in the order they're
     * in, for the list of its sections beside it.
     *
     * @param string $html - the page, from fetchPage() or render().
     *
     * @param int $deepestLevel - the deepest level to include, e.g. 4 for h2, h3 and h4.
     *
     * @return DocumentationHeadingCollection
     */
    public static function findHeadings(string $html, int $deepestLevel) : DocumentationHeadingCollection
    {
        $headings = [];
        preg_match_all('#<h([2-6]) id="([^"]+)">(.*?)</h\1>#s', $html, $matches, PREG_SET_ORDER);

        foreach ($matches as $match)
        {
            $level = (int)$match[1];
            $text = trim(html_entity_decode(strip_tags($match[3]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

            if ($level <= $deepestLevel && $text !== '')
            {
                $headings[] = new DocumentationHeading($level, html_entity_decode($match[2]), $text);
            }
        }

        return new DocumentationHeadingCollection(...$headings);
    }


    /**
     * Find the sections of a rendered page, as a tree: its level 2 sections, each with the sections within it, down to
     * a given level. A level that's skipped (e.g. an h4 straight after an h2) is nested under the heading above it.
     *
     * @param string $html - the page, from fetchPage() or render().
     *
     * @param int $deepestLevel - the deepest level to include, e.g. 4 for h2, h3 and h4.
     *
     * @return DocumentationSectionCollection
     */
    public static function findSections(string $html, int $deepestLevel) : DocumentationSectionCollection
    {
        $index = 0;
        return self::buildSections(self::findHeadings($html, $deepestLevel), $index, 1);
    }


    /**
     * Render some of the documentation's Markdown.
     *
     * @param string $markdown - the Markdown.
     *
     * @param DocumentationPage $page - the page it's from, which the links in it are relative to.
     *
     * @return string - the HTML.
     */
    public static function render(string $markdown, DocumentationPage $page) : string
    {
        static $converter = null;

        if ($converter === null)
        {
            $config = [
                'html_input' => 'escape',
                'allow_unsafe_links' => false,
                'heading_permalink' => [
                    'insert' => 'none',
                    'apply_id_to_heading' => true,
                    'id_prefix' => '',
                    'fragment_prefix' => '',
                ],
            ];

            $environment = new Environment($config);
            $environment->addExtension(new CommonMarkCoreExtension());
            $environment->addExtension(new GithubFlavoredMarkdownExtension());
            $environment->addExtension(new HeadingPermalinkExtension());
            $converter = new MarkdownConverter($environment);
        }

        $rewriteLink = function (array $matches) use ($page) : string {
            return self::rewriteLink($matches[1], $matches[2], $page);
        };

        $rewriteImage = function (array $matches) use ($page) : string {
            return self::rewriteImage($matches[1], $matches[2], $page);
        };

        $linked = (string)preg_replace_callback('/(?<!!)\[([^\]]*)\]\(([^)\s]+)\)/', $rewriteLink, $markdown);
        $withImages = (string)preg_replace_callback('/!\[([^\]]*)\]\(([^)\s]+)\)/', $rewriteImage, $linked);
        return $converter->convert($withImages)->getContent();
    }


    /**
     * Build the sections within a heading, from the headings after it that are below its level, moving $index past
     * them, so the caller carries on from the next heading at its level or above.
     *
     * @param DocumentationHeadingCollection $headings - every heading, in order.
     *
     * @param int $index - where to start, which is moved to the first heading not used.
     *
     * @param int $parentLevel - the level of the heading they're within, or 1 for the page itself.
     *
     * @return DocumentationSectionCollection
     */
    private static function buildSections(
        DocumentationHeadingCollection $headings,
        int &$index,
        int $parentLevel
    ) : DocumentationSectionCollection
    {
        $sections = [];

        while ($index < count($headings) && $headings->get($index)->getLevel() > $parentLevel)
        {
            $heading = $headings->get($index);
            $index++;
            $subsections = self::buildSections($headings, $index, $heading->getLevel());
            $sections[] = new DocumentationSection($heading, $subsections);
        }

        return new DocumentationSectionCollection(...$sections);
    }


    /**
     * Rewrite one Markdown link for the web interface: to another page of the docs, it goes to that page. To a web
     * address, a path on this site (e.g. "/openapi.json") or a section of the same page, it's kept. To any other file
     * in the repository, it's just its text.
     *
     * @param string $text - the link's text.
     *
     * @param string $target - where it goes, e.g. "retention.md#disk-space".
     *
     * @param DocumentationPage $page - the page it's on.
     *
     * @return string - the Markdown for it.
     */
    private static function rewriteLink(string $text, string $target, DocumentationPage $page) : string
    {
        $isKept = (preg_match('#^(https?://|mailto:|/|\#)#', $target) === 1);
        [$file, $fragment] = array_pad(explode('#', $target, 2), 2, '');
        $folder = dirname($page->getFile());
        $resolved = self::resolvePath(($folder === '.') ? $file : "{$folder}/{$file}");
        // docs/README.md lists the pages, which the side panel does in the web interface, so it goes to the overview.
        $linkedFile = ($resolved === 'docs/README.md') ? DocumentationPage::OVERVIEW->getFile() : $resolved;
        $isPage = self::isPageFile($linkedFile);
        $pagePath = $isPage ? self::getPageForFile($linkedFile)->getPagePath() : '';
        $anchor = ($fragment === '') ? '' : "#{$fragment}";

        return match (true) {
            $isKept => "[{$text}]({$target})",
            $isPage => "[{$text}]({$pagePath}{$anchor})",
            default => $text,
        };
    }


    /**
     * Rewrite one Markdown image for the web interface: a file in site/public (which the site serves, e.g. the
     * README's header image) is shown from its address on this site, so the same Markdown works on GitHub and here.
     * A path on this site is kept. Anything else is just its alt text: another file in the repository can't be shown,
     * and an image on another site (e.g. a badge) is blocked by the Content Security Policy. A linked badge, e.g.
     * "[![Docker Hub](https://img.shields.io/...)](https://hub.docker.com/...)", so becomes a link with that text.
     *
     * @param string $altText - the image's alt text.
     *
     * @param string $target - where the image is, e.g. "site/public/images/header.svg".
     *
     * @param DocumentationPage $page - the page it's on, which a relative $target is relative to.
     *
     * @return string - the Markdown for it.
     */
    private static function rewriteImage(string $altText, string $target, DocumentationPage $page) : string
    {
        // A path on this site, but not one starting "//", which is another site's address.
        $isKept = (preg_match('#^/(?!/)#', $target) === 1);
        $folder = dirname($page->getFile());
        $resolved = self::resolvePath(($folder === '.') ? $target : "{$folder}/{$target}");
        $publicPrefix = 'site/public/';
        $isPublic = str_starts_with($resolved, $publicPrefix);
        $publicPath = '/' . substr($resolved, strlen($publicPrefix));

        return match (true) {
            $isKept => "![{$altText}]({$target})",
            $isPublic => "![{$altText}]({$publicPath})",
            default => $altText,
        };
    }


    /**
     * Whether a file is one of the documentation's pages.
     *
     * @param string $file - the file, relative to the repository's root, e.g. "docs/retention.md".
     *
     * @return bool
     */
    private static function isPageFile(string $file) : bool
    {
        $isForFile = function (DocumentationPage $page) use ($file) : bool {
            return $page->getFile() === $file;
        };

        return array_any(DocumentationPage::cases(), $isForFile);
    }


    /**
     * Get the page whose file is this one.
     *
     * @param string $file - the file, relative to the repository's root, e.g. "docs/retention.md".
     *
     * @return DocumentationPage
     *
     * @throws LogicException - if it isn't one of the pages (see isPageFile()).
     */
    private static function getPageForFile(string $file) : DocumentationPage
    {
        $isForFile = function (DocumentationPage $page) use ($file) : bool {
            return $page->getFile() === $file;
        };

        $found = array_find(DocumentationPage::cases(), $isForFile);

        if ($found === null)
        {
            throw new LogicException("{$file} isn't one of the documentation's pages.");
        }

        return $found;
    }


    /**
     * Resolve the "." and ".." in a relative path, e.g. "docs/../README.md" is "README.md".
     *
     * @param string $path - the path.
     *
     * @return string
     */
    private static function resolvePath(string $path) : string
    {
        $parts = [];

        foreach (explode('/', $path) as $part)
        {
            if ($part === '..')
            {
                array_pop($parts);
            }
            elseif ($part !== '.' && $part !== '')
            {
                $parts[] = $part;
            }
        }

        return implode('/', $parts);
    }
}
