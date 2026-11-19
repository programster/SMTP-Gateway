<?php

/*
 * The outcome of checking one thing about a sender, or a provider, e.g. "example.org is authenticated with
 * SendGrid", with what to do about it if it isn't right.
 */

declare(strict_types = 1);


readonly class SenderCheck
{
    private SenderCheckLevel $m_level;
    private string $m_source;
    private string $m_title;
    private string $m_detail;


    /**
     * @param SenderCheckLevel $level - how serious it is.
     *
     * @param string $source - what it's about: "DNS", or the name of the provider it was checked with.
     *
     * @param string $title - what was found, in one short sentence, e.g. "No DMARC record".
     *
     * @param string $detail - what that means, and what to do about it, or an empty string if there's nothing more
     * to say.
     */
    public function __construct(SenderCheckLevel $level, string $source, string $title, string $detail)
    {
        $this->m_level = $level;
        $this->m_source = $source;
        $this->m_title = $title;
        $this->m_detail = $detail;
    }


    /**
     * Get it in the form the web interface's JSON endpoints return it in.
     *
     * @return array{level: string, source: string, title: string, detail: string}
     */
    public function toArray() : array
    {
        return [
            'level' => $this->m_level->value,
            'source' => $this->m_source,
            'title' => $this->m_title,
            'detail' => $this->m_detail,
        ];
    }


    # Accessors
    public function getLevel() : SenderCheckLevel { return $this->m_level; }
    public function getSource() : string { return $this->m_source; }
    public function getTitle() : string { return $this->m_title; }
    public function getDetail() : string { return $this->m_detail; }
}
