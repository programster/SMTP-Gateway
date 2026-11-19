<?php

/*
 * An email's body, for reading in the web interface: its HTML and plain text versions (either may be missing), in
 * UTF-8. Inline images the HTML refers to by "cid:" are put into it as data: URIs, so it can be shown without loading
 * anything from anywhere (see MessagesController::showHtml()).
 */

declare(strict_types = 1);

use ZBateson\MailMimeParser\Message;


final readonly class EmailContent
{
    // The largest inline image put into the HTML. Bigger ones are left as they are, so just don't show.
    private const int MAX_INLINE_IMAGE_BYTES = 5 * 1024 * 1024;

    private string $m_html;
    private string $m_text;


    /**
     * @param string $html - the HTML version, or an empty string if there isn't one.
     *
     * @param string $text - the plain text version, or an empty string if there isn't one.
     */
    private function __construct(string $html, string $text)
    {
        $this->m_html = $html;
        $this->m_text = $text;
    }


    /**
     * Read an email's HTML and plain text versions from its raw MIME form.
     * @param string $raw - the email, as it was received.
     * @return EmailContent
     */
    public static function createFromRaw(string $raw) : EmailContent
    {
        $message = Message::from($raw, false);
        $html = (string)$message->getHtmlContent();
        $text = (string)$message->getTextContent();
        return new EmailContent(self::inlineImages($message, $html), $text);
    }


    /**
     * Create the content of an email whose content isn't kept, or can't be shown (e.g. the service's own emails).
     * @return EmailContent
     */
    public static function createEmpty() : EmailContent
    {
        return new EmailContent('', '');
    }


    public function hasHtml() : bool
    {
        return trim($this->m_html) !== '';
    }


    public function hasText() : bool
    {
        return trim($this->m_text) !== '';
    }


    /**
     * Replace each "cid:" reference to an inline image in the HTML with the image itself, as a data: URI.
     *
     * @param Message $message - the email.
     *
     * @param string $html - its HTML.
     *
     * @return string - the HTML, with its inline images put in.
     */
    private static function inlineImages(Message $message, string $html) : string
    {
        $replaceReference = function (array $matches) use ($message) : string {
            $part = $message->getPartByContentId(rawurldecode($matches[2]));
            $type = ($part === null) ? '' : strtolower($part->getContentType());
            $stream = ($part === null) ? null : $part->getBinaryContentStream();
            $bytes = ($stream === null) ? '' : $stream->getContents();

            $isImage = (
                   str_starts_with($type, 'image/')
                && $bytes !== ''
                && strlen($bytes) <= self::MAX_INLINE_IMAGE_BYTES
            );

            return $isImage ? $matches[1] . "data:{$type};base64," . base64_encode($bytes) : $matches[0];
        };

        return (string)preg_replace_callback('/(["\'(]\s*)cid:([^"\')\s]+)/i', $replaceReference, $html);
    }


    # Accessors
    public function getHtml() : string { return $this->m_html; }
    public function getText() : string { return $this->m_text; }
}
