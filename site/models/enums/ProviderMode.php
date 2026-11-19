<?php

/*
 * How emails are shared between the providers in settings.yaml, when there's more than one.
 */

declare(strict_types = 1);


enum ProviderMode : string
{
    // Take turns between the providers, skipping any whose rate limits are full.
    case ROUND_ROBIN = 'round_robin';

    // Use the first provider, and only move on to the next while the earlier ones' rate limits are full.
    case OVERFLOW = 'overflow';

    // Use the first provider, and only move on to the next if it can't be connected to, or rejects the email. A
    // provider whose rate limits are full is waited for, rather than skipped.
    case FALLBACK = 'fallback';


    public function getDescription() : string
    {
        return match ($this) {
            ProviderMode::ROUND_ROBIN => 'Take turns between the providers, skipping any at their rate limits.',
            ProviderMode::OVERFLOW => 'Use the first provider, and the next only while earlier ones are at a limit.',
            ProviderMode::FALLBACK => 'Use the first provider, and the next only if it can\'t be reached or refuses.',
        };
    }


    /**
     * Get what it's called on the Providers page, e.g. "Round robin".
     * @return string
     */
    public function getLabel() : string
    {
        return match ($this) {
            ProviderMode::ROUND_ROBIN => 'Round robin',
            ProviderMode::OVERFLOW => 'Overflow',
            ProviderMode::FALLBACK => 'Fallback',
        };
    }


    /**
     * Explain what it does, and when it's useful, for choosing it on the Providers page.
     * @return string
     */
    public function getExplanation() : string
    {
        $roundRobin =
            'Take turns between the providers, one email each, skipping any at their rate limits. This spreads the'
            . ' emails, and their cost, evenly between them.';

        $overflow =
            'Send everything through the first provider, and only use the next while the ones before it are at their'
            . ' rate limits. Useful when the first is the cheapest, or has the best reputation, and the others are for'
            . ' busy times.';

        $fallback =
            'Send everything through the first provider, and only use the next if it can\'t be reached, or refuses'
            . ' the email. A provider at its rate limits is waited for, rather than skipped. Useful for a backup'
            . ' that\'s only for when the main one is down.';

        return match ($this) {
            ProviderMode::ROUND_ROBIN => $roundRobin,
            ProviderMode::OVERFLOW => $overflow,
            ProviderMode::FALLBACK => $fallback,
        };
    }
}
