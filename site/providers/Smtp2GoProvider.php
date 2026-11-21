<?php

/*
 * Sends email through SMTP2GO's SMTP relay, mail.smtp2go.com, logging in as one of the account's SMTP users.
 *
 * Every X-Smtp2go-* header the client sent is removed, so a client can't affect how SMTP2GO handles the email. Its
 * webhooks aren't read yet, so what happens to an email after SMTP2GO accepts it isn't recorded.
 */

declare(strict_types = 1);


final class Smtp2GoProvider extends SmtpRelayProvider
{
    /**
     * @param string $name - the provider's name in settings.yaml.
     *
     * @param string $username - the SMTP user's username.
     *
     * @param string $password - the SMTP user's password.
     *
     * @throws ConfigurationException - if the username or password is empty.
     */
    public function __construct(string $name, string $username, string $password)
    {
        if (trim($username) === '' || trim($password) === '')
        {
            $message = "The SMTP2GO provider \"{$name}\" needs an smtp_username and smtp_password.";
            throw new ConfigurationException($message);
        }

        $host = 'mail.smtp2go.com';
        $relaySettings = new SmtpRelaySettings($host, 587, trim($username), trim($password), SmtpSecurity::TLS);
        parent::__construct($name, $relaySettings);
    }


    public function getType() : ProviderType
    {
        return ProviderType::SMTP2GO;
    }


    /**
     * Remove any X-Smtp2go-* header the client sent.
     *
     * @param OutgoingEmail $email - the email.
     *
     * @return RawEmail
     */
    protected function prepareForSending(OutgoingEmail $email) : RawEmail
    {
        return $email->getRawEmail()->withoutHeadersStartingWith('X-Smtp2go-');
    }
}
