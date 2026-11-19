<?php

/*
 * Takes the attachments out of an email (in its raw MIME form), for keeping its content without them (as the
 * retention settings say). An attachment is any part other than the plain text and HTML body, so inline
 * images are included. In their place, it adds a short plain text attachment, attachments-removed.txt, listing what
 * was removed (each one's name, type and size), so anyone looking at the email can see it had them. Everything else
 * is left as it was.
 */

declare(strict_types = 1);

use ZBateson\MailMimeParser\Message;


final class AttachmentStripper
{
    public const string NOTE_FILENAME = 'attachments-removed.txt';


    /**
     * Take the attachments out of an email.
     *
     * @param string $raw - the email, in its raw MIME form.
     *
     * @return StrippedEmail - the email without its attachments (exactly as it was, if it had none), and how many
     * were removed.
     *
     * @throws InvalidInputException - if they can't be removed, e.g. as the whole email is a single attachment.
     */
    public static function strip(string $raw) : StrippedEmail
    {
        $message = Message::from($raw, false);
        $attachments = $message->getAllAttachmentParts();
        $lines = [];

        foreach ($attachments as $attachment)
        {
            /* @var $attachment \ZBateson\MailMimeParser\Message\IMessagePart */
            if ($attachment->getParent() === null)
            {
                $msg = 'The whole email is an attachment, so it can\'t be removed on its own.';
                throw new InvalidInputException($msg);
            }

            $lines[] = self::describe($attachment);
            $message->removePart($attachment);
        }

        if (count($lines) > 0)
        {
            $note =
                'This email had ' . count($lines) . ' attachment' . ((count($lines) === 1) ? '' : 's') . ', which'
                . ' the SMTP API service removed from its copy once it had been sent'
                . " (as its settings say attachments aren't kept for longer):\r\n\r\n"
                . implode("\r\n", $lines) . "\r\n";

            $message->addAttachmentPart($note, 'text/plain', self::NOTE_FILENAME);
            $stripped = new StrippedEmail((string)$message, count($lines));
        }
        else
        {
            $stripped = new StrippedEmail($raw, 0);
        }

        return $stripped;
    }


    /**
     * Describe an attachment for the note, e.g. "- invoice.pdf (application/pdf, 245.3 KB)".
     */
    private static function describe(\ZBateson\MailMimeParser\Message\IMessagePart $attachment) : string
    {
        $name = $attachment->getFilename();
        $content = $attachment->getBinaryContentStream();
        $bytes = ($content === null) ? 0 : ($content->getSize() ?? strlen($content->getContents()));
        $label = ($name === null || trim($name) === '') ? '(no name)' : str_replace(["\r", "\n"], ' ', $name);
        return "- {$label} ({$attachment->getContentType()}, " . self::describeBytes($bytes) . ')';
    }


    private static function describeBytes(int $bytes) : string
    {
        return match (true) {
            $bytes >= 1024 * 1024 => round($bytes / (1024 * 1024), 1) . ' MB',
            $bytes >= 1024 => round($bytes / 1024, 1) . ' KB',
            default => "{$bytes} bytes",
        };
    }
}
