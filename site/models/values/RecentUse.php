<?php

/*
 * When something was last used, and from where, for a dashboard widget's row: an account's last login, or an API
 * key's or email token's last use. Its name links to its own page.
 */

declare(strict_types = 1);


final readonly class RecentUse
{
    private string $m_name;
    private string $m_path;
    private string $m_usedAt;
    private string $m_ip;


    /**
     * @param string $name - what it's called, e.g. an account's email address.
     *
     * @param string $path - its page, e.g. "/admin/users/<id>".
     *
     * @param string $usedAt - when it was last used.
     *
     * @param string $ip - the IP address it was used from, or an empty string, if it was last used before they were
     * recorded.
     */
    private function __construct(string $name, string $path, string $usedAt, string $ip)
    {
        $this->m_name = $name;
        $this->m_path = $path;
        $this->m_usedAt = $usedAt;
        $this->m_ip = $ip;
    }


    public static function createForUser(AdminUser $user) : RecentUse
    {
        $path = '/admin/users/' . rawurlencode($user->getId());
        $usedAt = (string)$user->getLastLoginAt();
        return new RecentUse($user->getEmail(), $path, $usedAt, (string)$user->getLastLoginIp());
    }


    public static function createForApiKey(ApiKey $apiKey) : RecentUse
    {
        // API keys have no page of their own, so this is the page that lists them.
        $usedAt = (string)$apiKey->getLastUsedAt();
        return new RecentUse($apiKey->getName(), '/api-keys', $usedAt, (string)$apiKey->getLastUsedIp());
    }


    public static function createForEmailToken(EmailToken $emailToken) : RecentUse
    {
        $path = '/email-tokens/' . rawurlencode($emailToken->getId());
        $usedAt = (string)$emailToken->getLastUsedAt();
        return new RecentUse($emailToken->getName(), $path, $usedAt, (string)$emailToken->getLastUsedIp());
    }


    # Accessors
    public function getName() : string { return $this->m_name; }
    public function getPath() : string { return $this->m_path; }
    public function getUsedAt() : string { return $this->m_usedAt; }
    public function getIp() : string { return $this->m_ip; }
}
