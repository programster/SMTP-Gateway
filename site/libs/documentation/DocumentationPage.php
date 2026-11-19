<?php

/*
 * The pages of the documentation shown in the web interface, under Documentation in the side panel: the README (the
 * overview), and each page in docs/. Only these files are ever read, so no other file can be asked for. The docs are
 * copied into the image (and bind-mounted in the development stack) at DOCS_ROOT.
 */

declare(strict_types = 1);


enum DocumentationPage
{
    public const string DOCS_ROOT = '/var/www/documentation';
    public const string PATH = '/documentation';

    case OVERVIEW;
    case CONFIGURATION;
    case PROVIDERS;
    case SENDER_CHECKS;
    case SENDING_EMAIL;
    case RATE_LIMITS;
    case TRACKING;
    case WEB_INTERFACE;
    case MANAGEMENT_API;
    case SMALL_SERVERS;
    case RETENTION;
    case ADDING_A_PROVIDER;
    case DEVELOPMENT;
    case SECURITY;


    /**
     * Get the end of its page's path, which is also its file's name in docs/, e.g. "rate-limits". The overview has
     * none, as it's the documentation's first page.
     *
     * @return string
     */
    public function getSlug() : string
    {
        return ($this === DocumentationPage::OVERVIEW) ? '' : str_replace('_', '-', strtolower($this->name));
    }


    /**
     * Get its title, as it's shown in the menu, e.g. "Rate limits and the queue".
     * @return string
     */
    public function getTitle() : string
    {
        return match ($this) {
            DocumentationPage::OVERVIEW => 'Overview',
            DocumentationPage::CONFIGURATION => 'Configuration',
            DocumentationPage::PROVIDERS => 'Providers',
            DocumentationPage::SENDER_CHECKS => 'Checking senders',
            DocumentationPage::SENDING_EMAIL => 'Sending email',
            DocumentationPage::RATE_LIMITS => 'Rate limits and the queue',
            DocumentationPage::TRACKING => 'Tracking',
            DocumentationPage::WEB_INTERFACE => 'The web interface',
            DocumentationPage::MANAGEMENT_API => 'The management API',
            DocumentationPage::SMALL_SERVERS => 'Running on a small server',
            DocumentationPage::RETENTION => 'How long emails are kept',
            DocumentationPage::ADDING_A_PROVIDER => 'Adding a type of provider',
            DocumentationPage::DEVELOPMENT => 'Development',
            DocumentationPage::SECURITY => 'Security notes',
        };
    }


    /**
     * Get the path of its page in the web interface, e.g. "/documentation/rate-limits".
     * @return string
     */
    public function getPagePath() : string
    {
        return ($this === DocumentationPage::OVERVIEW) ? self::PATH : self::PATH . "/{$this->getSlug()}";
    }


    /**
     * Get its file, relative to the repository's root (and DOCS_ROOT), e.g. "docs/rate-limits.md".
     * @return string
     */
    public function getFile() : string
    {
        return ($this === DocumentationPage::OVERVIEW) ? 'README.md' : "docs/{$this->getSlug()}.md";
    }
}
