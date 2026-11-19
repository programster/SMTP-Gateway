<?php

/*
 * TOTP (authenticator app) second factor: setting it up, and checking codes. Each code can only be used once, by
 * remembering the last time step a code was accepted for, so a code seen over someone's shoulder (or captured by a
 * phishing page) can't be replayed within its 30 seconds.
 */

declare(strict_types = 1);

use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use OTPHP\TOTP;


final class TotpService
{
    // How many 30-second steps either side of now a code is accepted for, to allow for clock drift.
    private const int ALLOWED_DRIFT_STEPS = 1;


    /**
     * Generate a new secret for setting up an authenticator app. It's not stored until confirmed with a code
     * (see enable()), so a half-finished set up can never lock anyone out.
     *
     * @return string - the base32 secret.
     */
    public static function generateSecret() : string
    {
        return TOTP::generate(new SystemClock())->getSecret();
    }


    /**
     * Get the otpauth:// URI that authenticator apps read from a QR code.
     *
     * @param AdminUser $user - the account it's for.
     *
     * @param string $secret - the secret.
     *
     * @return string
     */
    public static function getProvisioningUri(AdminUser $user, string $secret) : string
    {
        $totp = TOTP::createFromSecret($secret, new SystemClock());
        $totp->setLabel($user->getEmail());
        $totp->setIssuer(SERVICE_NAME);
        return $totp->getProvisioningUri();
    }


    /**
     * Get a QR code of the provisioning URI, as an SVG data URI for an <img> tag.
     *
     * @param string $provisioningUri - the URI, from getProvisioningUri().
     *
     * @return string
     */
    public static function getQrCodeDataUri(string $provisioningUri) : string
    {
        return (new QRCode(new QROptions(['outputBase64' => true])))->render($provisioningUri);
    }


    /**
     * Turn on TOTP for an account, once the person has shown their app is set up by giving a valid code.
     *
     * @param AdminUser $user - the account.
     *
     * @param string $secret - the secret they set their app up with.
     *
     * @param string $code - a code from their app.
     *
     * @return void
     *
     * @throws InvalidInputException - if the code is wrong.
     */
    public static function enable(AdminUser $user, string $secret, string $code) : void
    {
        $step = self::findMatchingStep($secret, $code);

        if ($step === -1)
        {
            $msg = "That code isn't right. Check your device's clock is correct, and try again.";
            throw new InvalidInputException($msg);
        }

        $user->update(['totp_secret' => $secret, 'totp_last_used_step' => $step]);
    }


    /**
     * Check a code from an account's authenticator app, as a second factor. A code is only accepted once.
     *
     * @param AdminUser $user - the account. It must have TOTP turned on.
     *
     * @param string $code - the code given.
     *
     * @return bool
     */
    public static function verify(AdminUser $user, string $code) : bool
    {
        $isValid = false;

        if ($user->hasTotp())
        {
            $step = self::findMatchingStep((string)$user->getTotpSecret(), $code);
            $lastUsedStep = $user->getTotpLastUsedStep() ?? -1;

            if ($step !== -1 && $step > $lastUsedStep)
            {
                $user->update(['totp_last_used_step' => $step]);
                $isValid = true;
            }
        }

        return $isValid;
    }


    public static function disable(AdminUser $user) : void
    {
        $user->update(['totp_secret' => null, 'totp_last_used_step' => null]);
    }


    /**
     * Find which time step a code is valid for.
     *
     * @param string $secret - the secret.
     *
     * @param string $code - the code.
     *
     * @return int - the time step, or -1 if the code isn't valid for any step near now.
     */
    private static function findMatchingStep(string $secret, string $code) : int
    {
        $code = preg_replace('/\s+/', '', $code);
        $totp = TOTP::createFromSecret($secret, new SystemClock());
        $period = $totp->getPeriod();
        $currentStep = intdiv(time(), $period);
        $matchingStep = -1;

        if (preg_match('/^\d{6}$/', $code) === 1)
        {
            for ($offset = -self::ALLOWED_DRIFT_STEPS; $offset <= self::ALLOWED_DRIFT_STEPS; $offset++)
            {
                $step = $currentStep + $offset;

                if (hash_equals($totp->at($step * $period), $code))
                {
                    $matchingStep = $step;
                }
            }
        }

        return $matchingStep;
    }
}
