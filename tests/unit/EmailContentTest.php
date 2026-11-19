<?php

/*
 * Reading an email's HTML and plain text versions for the web interface (EmailContent), with its inline images put
 * into its HTML as data: URIs.
 */

declare(strict_types = 1);

require_once __DIR__ . '/TestSuite.php';

$suite = new TestSuite('Email content');
// A 1 by 1 pixel PNG.
$pixel = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

$raw =
    "From: svc@example.org\r\nTo: a@b.test\r\nSubject: both\r\nMIME-Version: 1.0\r\n"
    . "Content-Type: multipart/alternative; boundary=\"alt\"\r\n\r\n"
    . "--alt\r\nContent-Type: text/plain; charset=utf-8\r\n\r\nHello in plain text, caf\xC3\xA9\r\n"
    . "--alt\r\nContent-Type: multipart/related; boundary=\"rel\"\r\n\r\n"
    . "--rel\r\nContent-Type: text/html; charset=iso-8859-1\r\n\r\n"
    . "<p>Hello in HTML, caf\xE9</p><img src=\"cid:logo@example.org\"><img src=\"https://tracker.example/x.png\">\r\n"
    . "--rel\r\nContent-Type: image/png\r\nContent-ID: <logo@example.org>\r\nContent-Transfer-Encoding: base64\r\n\r\n"
    . "{$pixel}\r\n--rel--\r\n--alt--\r\n";

$content = EmailContent::createFromRaw($raw);
$suite->check('the plain text version is read', str_contains($content->getText(), 'Hello in plain text, café'));
$isHtml = str_contains($content->getHtml(), '<p>Hello in HTML, café</p>');
$suite->check('the HTML version is read, in UTF-8', $isHtml, $content->getHtml());
$isInlined = str_contains($content->getHtml(), 'src="data:image/png;base64,' . $pixel . '"');
$suite->check('an inline image is put in as a data: URI', $isInlined, $content->getHtml());
$isLeft = str_contains($content->getHtml(), 'src="https://tracker.example/x.png"');
$suite->check('a remote image is left as it is (the frame\'s policy blocks it)', $isLeft);

$textOnly = EmailContent::createFromRaw("From: svc@example.org\r\nSubject: text\r\n\r\njust text\r\n");
$suite->check('an email with just text has no HTML', $textOnly->hasText() && !$textOnly->hasHtml());

$htmlOnly = EmailContent::createFromRaw(
    "From: svc@example.org\r\nSubject: html\r\nMIME-Version: 1.0\r\nContent-Type: text/html\r\n\r\n<b>just HTML</b>\r\n"
);

$suite->check('an email with just HTML has no text', $htmlOnly->hasHtml() && !$htmlOnly->hasText());
$missing = EmailContent::createFromRaw(str_replace('cid:logo@example.org', 'cid:missing@example.org', $raw));
$isKept = str_contains($missing->getHtml(), 'src="cid:missing@example.org"');
$suite->check('a reference to an image that isn\'t there is left as it is', $isKept);
$empty = EmailContent::createEmpty();
$suite->check('empty content has neither', !$empty->hasHtml() && !$empty->hasText());

$suite->finish();
