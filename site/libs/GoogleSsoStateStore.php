<?php

/*
 * Keeps programster/google-sso's CSRF token (the OAuth "state") in this service's web session, between someone
 * leaving for Google and coming back. The package uses it up as soon as the callback arrives, so a callback can only
 * be used once.
 */

declare(strict_types = 1);

use Programster\GoogleSso\CsrfTokenStoreInterface;


final readonly class GoogleSsoStateStore implements CsrfTokenStoreInterface
{
    public function getToken() : string
    {
        return WebSession::getGoogleSsoState();
    }


    public function setToken(string $token) : void
    {
        WebSession::setGoogleSsoState($token);
    }


    public function removeToken() : void
    {
        WebSession::removeGoogleSsoState();
    }
}
