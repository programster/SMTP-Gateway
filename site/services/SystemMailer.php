<?php

/*
 * Sends the service's own emails (e.g. password reset links), from the address set on the Settings page. They go
 * through the queue like any other email, but with the system priority, so they go ahead of every email token's
 * emails (see MessageDispatcher::addServiceEmailToTransaction()). They count towards the global and per-provider rate
 * limits, and are retried if the providers fail, but their content is never kept once sent, as it holds password reset
 * links.
 */

declare(strict_types = 1);

use PHPMailer\PHPMailer\PHPMailer;


final class SystemMailer
{
    /**
     * Add a plain text email to the transaction being built (see ServiceFactory::getTransaction()), so it's queued if,
     * and only if, that commits.
     *
     * @param EmailAddress $to - who to send it to.
     *
     * @param string $subject - its subject.
     *
     * @param string $text - its body.
     *
     * @param string $clientIp - the IP address of whoever caused it to be sent.
     *
     * @return void
     *
     * @throws ConfigurationException - if no address has been set for the service's own emails (on the Settings page),
     * or the email couldn't be built.
     */
    public static function addToTransaction(EmailAddress $to, string $subject, string $text, string $clientIp) : void
    {
        $from = Settings::get()->getSending()->getSystemEmailFrom();
        $mailer = new PHPMailer(true);
        $mailer->CharSet = PHPMailer::CHARSET_UTF8;
        $mailer->Hostname = Config::get()->getSmtpServerHostname();

        try
        {
            $mailer->setFrom((string)$from, SERVICE_NAME, false);
            $mailer->addAddress((string)$to);
            $mailer->Subject = $subject;
            $mailer->Body = $text;
            $mailer->preSend();
        }
        catch (\PHPMailer\PHPMailer\Exception $e)
        {
            throw new ConfigurationException("The email couldn't be built: {$e->getMessage()}");
        }

        $recipients = new EmailAddressCollection($to);
        $raw = new RawEmail($mailer->getSentMIMEMessage());
        MessageDispatcher::addServiceEmailToTransaction($from, $recipients, $raw, $clientIp);
    }
}
