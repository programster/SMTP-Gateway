<?php

/*
 * The values to fill the email token form in with (see ViewEmailTokenFields), by field name, as PolicyInput reads
 * them back: "name", "priority", each list's rows ("allowed_ips_0", "allowed_senders_0" and so on), and each rate
 * limit's ("rate_limit_max_0", "rate_limit_unit_0" and "rate_limit_period_0").
 */

declare(strict_types = 1);


final class EmailTokenFormValues
{
    /**
     * Get the values for editing a token: its name, and its policy as it is now.
     *
     * @param EmailToken $emailToken - the token.
     *
     * @param EmailTokenPolicy $policy - its policy.
     *
     * @return array<string, string>
     */
    public static function createFromToken(EmailToken $emailToken, EmailTokenPolicy $policy) : array
    {
        $values = ['name' => $emailToken->getName(), 'priority' => (string)$policy->getPriority()->getValue()];
        $networks = $policy->getAllowedNetworks();

        if ($networks instanceof IpAllowList)
        {
            foreach (array_values(iterator_to_array($networks->getNetworks(), false)) as $index => $network)
            {
                $values["allowed_ips_{$index}"] = (string)$network;
            }
        }

        foreach (AddressRuleField::cases() as $field)
        {
            $restriction = $policy->getRulesFor($field);

            if ($restriction instanceof AddressRuleSet)
            {
                $prefix = self::getRowsPrefix($field);

                foreach (array_values(iterator_to_array($restriction->getRules(), false)) as $index => $rule)
                {
                    $values["{$prefix}_{$index}"] = (string)$rule;
                }
            }
        }

        foreach (array_values(iterator_to_array($policy->getRateLimits()->getLimits(), false)) as $index => $limit)
        {
            /* @var $limit RateLimit */
            $values["rate_limit_max_{$index}"] = (string)$limit->getMax();
            $values["rate_limit_unit_{$index}"] = $limit->getUnit()->value;
            $values["rate_limit_period_{$index}"] = (string)$limit->getPeriodSeconds();
        }

        return $values;
    }


    /**
     * Get the prefix of the form's rows for one of the address lists, as PolicyInput::readPolicyFromForm() reads it.
     *
     * @param AddressRuleField $field - the list.
     *
     * @return string - e.g. "allowed_senders".
     */
    private static function getRowsPrefix(AddressRuleField $field) : string
    {
        return match ($field) {
            AddressRuleField::SENDER => 'allowed_senders',
            AddressRuleField::REPLY_TO => 'allowed_reply_tos',
            AddressRuleField::RECIPIENT => 'allowed_recipients',
        };
    }
}
