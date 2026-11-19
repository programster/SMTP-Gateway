<?php

/*
 * Checks an email against what its email token is allowed to send. The checks are split up so that the SMTP server can
 * reject a disallowed sender or recipient as soon as it's given, as SMTP clients expect.
 */

declare(strict_types = 1);


final class PolicyEnforcer
{
    /**
     * Check one address against the token's rules for a field.
     *
     * @param AuthenticatedEmailToken $emailToken - the email token.
     *
     * @param AddressRuleField $field - which part of the email the address is in.
     *
     * @param EmailAddress $address - the address.
     *
     * @return void
     *
     * @throws RequestRejectedException - if the token isn't allowed to use the address there. It's recorded.
     */
    public static function checkAddress(
        AuthenticatedEmailToken $emailToken,
        AddressRuleField $field,
        EmailAddress $address
    ) : void
    {
        if ($emailToken->getPolicy()->getRulesFor($field)->allows($address) === false)
        {
            $description = match ($field) {
                AddressRuleField::SENDER => "send as {$address}",
                AddressRuleField::REPLY_TO => "use {$address} as the reply-to address",
                AddressRuleField::RECIPIENT => "send to {$address}",
            };

            $msg = "This email token isn't allowed to {$description}.";
            $emailToken->reject(RejectionReason::createForField($field), $msg);
        }
    }


    /**
     * Check the addresses in an email's headers: every From and Sender address must be an allowed sender, and every
     * Reply-To address must be an allowed reply-to. (The envelope sender and recipients are checked separately.)
     *
     * @param AuthenticatedEmailToken $emailToken - the email token.
     *
     * @param RawEmail $email - the email.
     *
     * @return void
     *
     * @throws RequestRejectedException - if an address isn't allowed, there's no From header, or a header can't be
     * parsed. It's recorded.
     */
    public static function checkHeaders(AuthenticatedEmailToken $emailToken, RawEmail $email) : void
    {
        try
        {
            $fromAddresses = $email->getAddressesInHeader('From');
            $senderAddresses = $email->getAddressesInHeader('Sender');
            $replyToAddresses = $email->getAddressesInHeader('Reply-To');
        }
        catch (InvalidInputException $e)
        {
            $msg = "The email's headers couldn't be read: {$e->getMessage()}";
            $emailToken->reject(RejectionReason::INVALID_MESSAGE, $msg);
        }

        if (count($fromAddresses) === 0)
        {
            $emailToken->reject(RejectionReason::INVALID_MESSAGE, 'The email has no From header.');
        }

        foreach ([...$fromAddresses, ...$senderAddresses] as $address)
        {
            self::checkAddress($emailToken, AddressRuleField::SENDER, $address);
        }

        foreach ($replyToAddresses as $address)
        {
            self::checkAddress($emailToken, AddressRuleField::REPLY_TO, $address);
        }
    }
}
