<?php

/*
 * Removing an email's attachments while keeping its headers and body, and a note of what was removed.
 */

declare(strict_types = 1);

require_once __DIR__ . '/TestSuite.php';

$suite = new TestSuite('Attachment stripper');

$pdf = base64_encode(str_repeat('%PDF-1.4 fake ', 2000));
$png = base64_encode(str_repeat("\x89PNG", 500));

// A PDF attachment, and an HTML body with an inline image.
$email =
    "From: svc@example.org\r\nTo: a@b.test\r\nSubject: Invoice\r\nX-Custom: kept-header\r\nMIME-Version: 1.0\r\n"
    . "Content-Type: multipart/mixed; boundary=\"b1\"\r\n\r\n"
    . "--b1\r\nContent-Type: multipart/related; boundary=\"r1\"\r\n\r\n"
    . "--r1\r\nContent-Type: text/html; charset=utf-8\r\n\r\n<p>Hello <img src=\"cid:logo\"></p>\r\n"
    . "--r1\r\nContent-Type: image/png\r\nContent-ID: <logo>\r\nContent-Disposition: inline; filename=\"logo.png\"\r\n"
    . "Content-Transfer-Encoding: base64\r\n\r\n" . chunk_split($png, 76, "\r\n")
    . "--r1--\r\n"
    . "--b1\r\nContent-Type: application/pdf; name=\"invoice.pdf\"\r\n"
    . "Content-Disposition: attachment; filename=\"invoice.pdf\"\r\nContent-Transfer-Encoding: base64\r\n\r\n"
    . chunk_split($pdf, 76, "\r\n")
    . "--b1--\r\n";

$stripped = AttachmentStripper::strip($email);
$result = $stripped->getRaw();
$count = $stripped->getRemovedCount();
$suite->check('both removed (the inline image and the PDF)', $count === 2, (string)$count);
$suite->check('much smaller', strlen($result) < strlen($email) / 5, strlen($result) . ' vs ' . strlen($email));
$hasHeaders = str_contains($result, 'X-Custom: kept-header') && str_contains($result, 'Subject: Invoice');
$suite->check('headers kept', $hasHeaders);
$suite->check('HTML body kept', str_contains($result, '<p>Hello <img src="cid:logo"></p>'));
$suite->check('note added', str_contains($result, 'attachments-removed.txt'));
$reparsed = \ZBateson\MailMimeParser\Message::from($result, false);
$note = $reparsed->getAttachmentPart(0)->getContent();
$isListed = str_contains($note, 'invoice.pdf (application/pdf, 27.3 KB)') && str_contains($note, 'logo.png (image/png');
$suite->check('the note lists both', $isListed, $note);
$suite->check('the result has one attachment: the note', $reparsed->getAttachmentCount() === 1);
$suite->check('its HTML can still be read', str_contains((string)$reparsed->getHtmlContent(), 'Hello'));

$plain = "From: a@b.org\r\nSubject: hi\r\n\r\nplain body\r\n";
$strippedPlain = AttachmentStripper::strip($plain);
$isUnchanged = $strippedPlain->getRaw() === $plain && $strippedPlain->getRemovedCount() === 0;
$suite->check('an email with no attachments is unchanged', $isUnchanged);

$alternative =
    "From: a@b.org\r\nMIME-Version: 1.0\r\nContent-Type: multipart/alternative; boundary=x\r\n\r\n"
    . "--x\r\nContent-Type: text/plain\r\n\r\ntext\r\n--x\r\nContent-Type: text/html\r\n\r\n<b>html</b>\r\n--x--\r\n";

$count = AttachmentStripper::strip($alternative)->getRemovedCount();
$suite->check('text and HTML alternatives aren\'t attachments', $count === 0, (string)$count);

$stripWholeEmail = function () use ($pdf) : void {
    $whole = "From: a@b.org\r\nMIME-Version: 1.0\r\nContent-Type: application/pdf\r\nContent-Transfer-Encoding: base64";
    AttachmentStripper::strip("{$whole}\r\n\r\n{$pdf}\r\n");
};

$suite->checkThrows('an email that\'s just an attachment is refused', InvalidInputException::class, $stripWholeEmail);

$bigAttachment = chunk_split(base64_encode(random_bytes(15 * 1024 * 1024)), 76, "\r\n");

$big =
    "From: a@b.org\r\nMIME-Version: 1.0\r\nContent-Type: multipart/mixed; boundary=z\r\n\r\n"
    . "--z\r\nContent-Type: text/plain\r\n\r\nhi\r\n"
    . "--z\r\nContent-Type: application/octet-stream\r\nContent-Disposition: attachment; filename=big.bin\r\n"
    . "Content-Transfer-Encoding: base64\r\n\r\n" . $bigAttachment
    . "--z--\r\n";

$started = microtime(true);
$strippedBig = AttachmentStripper::strip($big);
$detail = sprintf('%.2fs, peak %d MB', microtime(true) - $started, memory_get_peak_usage() / 1048576);
$isRemoved = $strippedBig->getRemovedCount() === 1 && strlen($strippedBig->getRaw()) < 2000;
$suite->check('a 15 MB attachment is removed within the memory limit', $isRemoved, $detail);

$suite->finish();
