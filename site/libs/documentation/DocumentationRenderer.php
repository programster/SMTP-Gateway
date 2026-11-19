<?php

/*
 * Turns the documentation's Markdown into HTML for the web interface, with league/commonmark (GitHub-flavoured, for
 * the tables). Any raw HTML in the Markdown is escaped, and unsafe links (e.g. javascript:) are dropped, so a page
 * can't be used to inject anything. Headings get GitHub's IDs, so links to a section work as they do on GitHub.
 *
 * Links between the docs are made to point at their pages in the web interface. A link to any other file in the
 * repository (e.g. .env.example) becomes plain text, as the web interface doesn't serve those.
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

        $linked = (string)preg_replace_callback('/(?<!!)\[([^\]]*)\]\(([^)\s]+)\)/', $rewriteLink, $markdown);
        return $converter->convert($linked)->getContent();
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
     * Whether a file is one of the documentation's pages.
     *
     * @param string $file - the file, relative to the repository's root, e.g. "docs/retention.md".
     *
     * @return bool
     */
    private static function isPageFile(string $file) : bool
    {
        $isPage = false;

        foreach (DocumentationPage::cases() as $page)
        {
            $isPage = ($isPage || $page->getFile() === $file);
        }

        return $isPage;
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
        $pages = DocumentationPage::cases();
        $found = null;
        $index = 0;

        while ($found === null && $index < count($pages))
        {
            $found = ($pages[$index]->getFile() === $file) ? $pages[$index] : null;
            $index++;
        }

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
