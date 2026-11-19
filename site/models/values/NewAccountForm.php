<?php

/*
 * What the form for adding an account is filled in with: empty to begin with, or what was sent, when it failed. The
 * passwords are never filled back in.
 */

declare(strict_types = 1);


final readonly class NewAccountForm
{
    private string $m_email;
    private AccountPasswordSource $m_passwordSource;
    private bool $m_mustChangePassword;


    /**
     * @param string $email - the email address.
     *
     * @param AccountPasswordSource $passwordSource - how the account gets its password.
     *
     * @param bool $mustChangePassword - whether a password set now has to be changed when they first log in.
     */
    public function __construct(string $email, AccountPasswordSource $passwordSource, bool $mustChangePassword)
    {
        $this->m_email = $email;
        $this->m_passwordSource = $passwordSource;
        $this->m_mustChangePassword = $mustChangePassword;
    }


    /**
     * Create the form as it starts: emailing a link, or, if a password is set instead, having it changed.
     * @return NewAccountForm
     */
    public static function createEmpty() : NewAccountForm
    {
        return new NewAccountForm('', AccountPasswordSource::EMAILED_LINK, true);
    }


    # Accessors
    public function getEmail() : string { return $this->m_email; }
    public function getPasswordSource() : AccountPasswordSource { return $this->m_passwordSource; }
    public function mustChangePassword() : bool { return $this->m_mustChangePassword; }
}
