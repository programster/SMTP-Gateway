<?php

/*
 * A provider ("driver") that email is sent through, e.g. SendGrid. To plug in a new type of provider, implement this
 * interface (and ProviderWebhookInterface, if the provider can report what happened to each email), add a case to
 * ProviderType, a settings class implementing ProviderSettingsInterface, and read it in ProvidersConfigParser.
 *
 * A provider is given each email in its raw form, exactly as the client sent it, so nothing (custom headers,
 * multipart structure, attachments, encodings) is lost on the way through. A provider may keep its connection open
 * between emails, which the queue workers rely on for speed, and must close it in disconnect().
 */

declare(strict_types = 1);


interface SmtpProviderInterface
{
    /**
     * Get the provider's name in settings.yaml.
     *
     * @return string
     */
    public function getName() : string;


    public function getType() : ProviderType;


    /**
     * Hand an email to the provider for delivery.
     *
     * @param OutgoingEmail $email - the email, and who to deliver it to.
     *
     * @return string - the provider's ID for the email, if it gives one, or an empty string.
     *
     * @throws ProviderUnavailableException - if the provider couldn't be connected to or logged in to.
     *
     * @throws ProviderTemporaryFailureException - if it failed in a way that may succeed if retried later (it timed
     * out, or replied with a 4xx code). The email will be retried.
     *
     * @throws ProviderPermanentFailureException - if the provider refused the email outright (e.g. a 5xx reply
     * because the sender isn't verified with it).
     */
    public function send(OutgoingEmail $email) : string;


    /**
     * Close any connection the provider is keeping open.
     *
     * @return void
     */
    public function disconnect() : void;
}
