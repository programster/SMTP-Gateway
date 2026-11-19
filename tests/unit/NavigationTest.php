<?php

/*
 * The side panel's menu: which link and section count as the page being shown, and the mistakes its classes refuse.
 */

declare(strict_types = 1);

require_once __DIR__ . '/TestSuite.php';

$suite = new TestSuite('Navigation');
$emails = new NavLink('Emails', '/messages');
$dashboard = new NavLink('Dashboard', '/');

$suite->check('a link is current on its own page', $emails->isCurrentFor('/messages'));
$suite->check('and on a page below it', $emails->isCurrentFor('/messages/123'));
$suite->check('but not on a page that only starts the same', $emails->isCurrentFor('/messages-old') === false);
$suite->check('the dashboard is current on "/"', $dashboard->isCurrentFor('/'));
$suite->check('but not on every page', $dashboard->isCurrentFor('/messages') === false);
$overview = new NavLink('Overview', '/documentation', NavMatch::PAGE_ONLY);
$suite->check('a link that matches only its page is current on it', $overview->isCurrentFor('/documentation'));
$suite->check('but not on a page below it', $overview->isCurrentFor('/documentation/rate-limits') === false);

$activity = new NavSection('Activity', Icon::ACTIVITY, $emails, new NavLink('Rejections', '/rejections'));
$suite->check('a section is current on one of its pages', $activity->isCurrentFor('/rejections'));
$suite->check('and not on others', $activity->isCurrentFor('/email-tokens') === false);

$createBadPath = function () : void {
    new NavLink('Emails', 'messages');
};

$createTrailingSlash = function () : void {
    new NavLink('Emails', '/messages/');
};

$createOneLinkSection = function () : void {
    new NavSection('Activity', Icon::ACTIVITY, new NavLink('Emails', '/messages'));
};

$suite->checkThrows('a path without a leading slash is refused', LogicException::class, $createBadPath);
$suite->checkThrows('a path with a trailing slash is refused', LogicException::class, $createTrailingSlash);
$suite->checkThrows('a section of one link is refused', LogicException::class, $createOneLinkSection);

// Every page in the menu is current for itself, and only one link is current on it.
foreach (ViewSidebar::createMenu() as $entry)
{
    $links = ($entry instanceof NavSection) ? iterator_to_array($entry->getLinks()) : [$entry];

    foreach ($links as $link)
    {
        $html = (string)new ViewSidebar($link->getPath());
        $currentCount = substr_count($html, 'aria-current="page"');
        $suite->check("only {$link->getLabel()} is current on its page", $currentCount === 1, (string)$currentCount);
    }
}

// The icons: each has its file, every top-level entry of the menu has one, and they're put into the page.
foreach (Icon::cases() as $icon)
{
    if ($icon !== Icon::NONE)
    {
        $suite->check("the {$icon->value} icon's file is there", is_file($icon->getFilePath()), $icon->getFilePath());
    }
}

foreach (ViewSidebar::createMenu() as $entry)
{
    $suite->check("{$entry->getLabel()} has an icon", $entry->getIcon() !== Icon::NONE);
}

$html = (string)new ViewSidebar('/');
$iconCount = substr_count($html, '<svg class="icon"');
$isEveryIcon = ($iconCount === count(ViewSidebar::createMenu()) + 2);
$suite->check('the menu\'s icons are in the page, and the account\'s two', $isEveryIcon, (string)$iconCount);
$suite->check('a link in a section has none', (string)new ViewIcon(Icon::NONE) === '');

// The settings' pages, in the order they're listed: the providers straight after General.
$settingsLabels = [];

foreach (ViewSidebar::createMenu() as $entry)
{
    if ($entry instanceof NavSection && $entry->getLabel() === 'Settings')
    {
        foreach ($entry->getLinks() as $link)
        {
            $settingsLabels[] = $link->getLabel();
        }
    }
}

$expected = ['General', 'Providers', 'Security', 'Proxies', 'Email retention', 'SSO', 'Server resources'];
$suite->check('the settings are listed in order', $settingsLabels === $expected, implode(', ', $settingsLabels));

$suite->finish();
