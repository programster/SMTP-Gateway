<?php

/*
 * The JSON shapes the API returns. Kept in one place so that the API and its OpenAPI document agree.
 */

declare(strict_types = 1);


final class ApiPresenter
{
    public static function presentEmailToken(EmailToken $emailToken) : array
    {
        $policy = EmailTokenRepository::loadPolicy($emailToken);

        return [
            'id' => $emailToken->getId(),
            'name' => $emailToken->getName(),
            'createdAt' => Time::toIso($emailToken->getCreatedAt()),
            'lastUsedAt' => self::presentOptionalTime($emailToken->getLastUsedAt()),
            'revokedAt' => self::presentOptionalTime($emailToken->getRevokedAt()),
            'conditions' => self::presentConditions($policy),
            'rateLimits' => self::presentRateLimits($policy->getRateLimits()),
            'priority' => $policy->getPriority()->getValue(),
        ];
    }


    /**
     * An account that can log in to the web interface. An archived one's email address is null, as it has none.
     */
    public static function presentUser(AdminUser $user) : array
    {
        $isArchived = ($user->getStatus() === AccountStatus::ARCHIVED);

        return [
            'id' => $user->getId(),
            'status' => $user->getStatus()->value,
            'email' => $isArchived ? null : $user->getEmail(),
            'createdAt' => Time::toIso($user->getCreatedAt()),
            'statusChangedAt' => self::presentOptionalTime($user->getStatusChangedAt()),
            'lastLoginAt' => self::presentOptionalTime($user->getLastLoginAt()),
        ];
    }


    public static function presentRateLimits(RateLimitSet $limits) : array
    {
        $output = [];

        foreach ($limits->getLimits() as $limit)
        {
            /* @var $limit RateLimit */
            $output[] = [
                'unit' => $limit->getUnit()->value,
                'max' => $limit->getMax(),
                'periodSeconds' => $limit->getPeriodSeconds(),
            ];
        }

        return $output;
    }


    public static function presentMessage(EmailMessage $message) : array
    {
        return [
            'id' => $message->getId(),
            'emailTokenId' => $message->getEmailTokenId(),
            'source' => $message->getSource(),
            'clientIp' => $message->getClientIp(),
            'envelopeSender' => $message->getEnvelopeSender(),
            'recipients' => json_decode($message->getRecipients(), true),
            'subject' => $message->getSubject(),
            'sizeBytes' => $message->getSizeBytes(),
            'status' => $message->getStatus(),
            'wasQueued' => $message->wasQueued(),
            'attempts' => $message->getAttempts(),
            'createdAt' => Time::toIso($message->getCreatedAt()),
            'sentAt' => self::presentOptionalTime($message->getSentAt()),
            'provider' => $message->getProvider(),
            'providerMessageId' => $message->getProviderMessageId(),
            'lastError' => $message->getLastError(),
            'hasContent' => $message->getRawMessageBase64() !== null && $message->isFromService() === false,
            'priority' => $message->getPriority(),
            'removedAttachments' => $message->getRemovedAttachmentCount(),
        ];
    }


    public static function presentAttempt(EmailAttempt $attempt) : array
    {
        $finishedAt = $attempt->getFinishedAt();

        return [
            'provider' => $attempt->getProvider(),
            'outcome' => $attempt->getOutcome()->value,
            'dispatchedAt' => Time::toIso($attempt->getDispatchedAt()),
            'finishedAt' => ($finishedAt === '') ? null : Time::toIso($finishedAt),
            'error' => ($attempt->getError() === '') ? null : $attempt->getError(),
        ];
    }


    public static function presentEvent(EmailEvent $event) : array
    {
        return [
            'type' => $event->getEventType(),
            'recipient' => $event->getRecipient(),
            'detail' => $event->getDetail(),
            'occurredAt' => Time::toIso($event->getOccurredAt()),
        ];
    }


    /**
     * An email token's conditions, in the form the API takes them: only the ones it has, as a field with no restriction
     * has no condition.
     */
    private static function presentConditions(EmailTokenPolicy $policy) : array
    {
        $conditions = [];
        $networks = $policy->getAllowedNetworks();

        if ($networks instanceof IpAllowList)
        {
            $conditions[] = ['field' => ConditionField::CLIENT_IP->value, 'allowed' => $networks->toStrings()];
        }

        foreach (AddressRuleField::cases() as $field)
        {
            $restriction = $policy->getRulesFor($field);

            if ($restriction instanceof AddressRuleSet)
            {
                $rules = [];

                foreach ($restriction->getRules() as $rule)
                {
                    /* @var $rule AddressRule */
                    $rules[] = ['type' => $rule->getKind()->value, 'value' => $rule->getValue()];
                }

                $conditions[] = ['field' => ConditionField::createForAddressField($field)->value, 'allowed' => $rules];
            }
        }

        // A token whose providers have all been removed stays restricted, to none, so it's an empty list.
        $providers = $policy->getAllowedProviders();

        $providerIds = match (true) {
            $providers instanceof ProviderAllowList => $providers->getIds()->toStrings(),
            $providers instanceof NoRemainingProviders => [],
            default => null,
        };

        if ($providerIds !== null)
        {
            $conditions[] = ['field' => ConditionField::PROVIDER->value, 'allowed' => $providerIds];
        }

        return $conditions;
    }


    /**
     * A time that may not have happened yet (e.g. when a token was revoked), as ISO 8601, or null.
     */
    private static function presentOptionalTime(?string $databaseTime) : ?string
    {
        return ($databaseTime === null) ? null : Time::toIso($databaseTime);
    }
}
